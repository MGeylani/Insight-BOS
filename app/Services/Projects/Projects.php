<?php

namespace App\Services\Projects;

use App\Models\ChangeRequest;
use App\Models\Contract;
use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\Quote;
use App\Services\ActivityLogger;
use App\Services\NumberSequencer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only place that creates projects, changes their status, members and milestones, and decides
 * change requests.
 *
 *   planning --> active --> completed
 *        \          \--> on_hold --> active
 *         '-----------'--> cancelled
 *
 * A project may come from an accepted quote or from a sent/signed contract of the same client; each
 * of those turns into one project only (quotes.project_id / contracts.project_id point back to it).
 */
class Projects
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{user_id: int, role?: string|null}>  $members
     */
    public static function create(array $data, array $members, ?string $template, int $userId): Project
    {
        self::checkSources($data, null);

        return DB::transaction(function () use ($data, $members, $template, $userId) {
            $project = new Project($data + ['created_by' => $userId]);
            $project->status = $data['status'] ?? 'planning';
            $project->number = NumberSequencer::next('project');
            $project->template = $template && self::template($template) ? $template : null;
            if ($project->status === 'completed') {
                $project->completed_at = now();
            }
            $project->save();

            self::syncMembers($project, $members, false);
            if ($project->template) {
                self::applyTemplate($project, $project->template);
            }
            self::linkSources($project);

            ActivityLogger::log('projects.created', $project, "إنشاء المشروع {$project->label()}", [
                'client_id' => $project->client_id, 'quote_id' => $project->quote_id, 'contract_id' => $project->contract_id,
                'template' => $project->template,
            ]);

            return $project;
        });
    }

    /** @param array<string, mixed> $data */
    public static function update(Project $project, array $data): Project
    {
        self::checkSources($data, $project);

        return DB::transaction(function () use ($project, $data) {
            $oldStatus = $project->status;
            $project->fill($data);
            if ($project->status !== $oldStatus) {
                $project->completed_at = $project->status === 'completed' ? now() : null;
            }
            $project->save();
            self::linkSources($project);

            $changes = ActivityLogger::diff($project);
            if ($changes) {
                $label = config("projects.statuses.{$project->status}");
                ActivityLogger::log('projects.updated', $project, isset($changes['status'])
                    ? "تغيير حالة المشروع إلى «{$label}»" : "تعديل بيانات المشروع {$project->label()}", $changes);
            }

            return $project;
        });
    }

    /**
     * Replace the member list. The manager is never listed twice.
     *
     * @param  list<array{user_id: int, role?: string|null}>  $rows
     */
    public static function syncMembers(Project $project, array $rows, bool $log = true): void
    {
        $map = [];
        foreach ($rows as $row) {
            $id = (int) ($row['user_id'] ?? 0);
            if ($id && $id !== (int) $project->manager_id) {
                $map[$id] = ['role' => ($row['role'] ?? null) ?: null];
            }
        }
        $result = $project->members()->sync($map);
        $project->unsetRelation('members');

        if ($log && (array_filter($result))) {
            ActivityLogger::log('projects.members', $project, 'تحديث فريق المشروع', [
                'attached' => $result['attached'], 'detached' => $result['detached'], 'updated' => $result['updated'],
            ]);
        }
    }

    /** @return array{milestones: list<array<string, mixed>>, risks: list<array<string, mixed>>}|null */
    public static function template(?string $key): ?array
    {
        return $key ? config("projects.templates.$key") : null;
    }

    /** Add the template's milestones (due = start + offset) and usual risks. */
    public static function applyTemplate(Project $project, string $key): void
    {
        $tpl = self::template($key);
        if (! $tpl) {
            return;
        }
        $start = $project->start_date ? Carbon::parse($project->start_date) : null;
        $sort = (int) $project->milestones()->max('sort');
        foreach ($tpl['milestones'] ?? [] as $m) {
            $project->milestones()->create([
                'title' => $m['title'],
                'details' => $m['details'] ?? null,
                'due_date' => $start ? $start->copy()->addDays((int) ($m['offset_days'] ?? 0)) : null,
                'status' => 'pending',
                'sort' => ++$sort,
            ]);
        }
        foreach ($tpl['risks'] ?? [] as $r) {
            $project->risks()->create($r + ['status' => 'open', 'owner_id' => $project->manager_id, 'created_by' => $project->created_by]);
        }
        $project->unsetRelation('milestones')->unsetRelation('risks');
    }

    public static function setMilestone(ProjectMilestone $row, bool $done, int $userId): ProjectMilestone
    {
        $row->forceFill($done
            ? ['status' => 'done', 'done_at' => now(), 'done_by' => $userId]
            : ['status' => 'pending', 'done_at' => null, 'done_by' => null])->save();
        ActivityLogger::log('projects.milestone', $row->project, ($done ? 'إنجاز المرحلة: ' : 'إعادة فتح المرحلة: ').$row->title);

        return $row;
    }

    /** @param array<string, mixed> $data */
    public static function requestChange(Project $project, array $data, int $userId): ChangeRequest
    {
        return DB::transaction(function () use ($project, $data, $userId) {
            // Lock the project row so two requests never share a sequence number.
            Project::whereKey($project->id)->lockForUpdate()->first();
            $cr = $project->changeRequests()->create($data + [
                'seq' => (int) ChangeRequest::where('project_id', $project->id)->max('seq') + 1,
                'created_by' => $userId,
            ]);
            ActivityLogger::log('projects.change_requested', $project, "طلب تغيير {$cr->code()}: {$cr->title}", [
                'cost_impact' => (float) $cr->cost_impact, 'days_impact' => $cr->days_impact,
            ]);

            return $cr;
        });
    }

    /** Approve: the budget grows (or shrinks) by the cost impact and the due date moves by the days impact. */
    public static function approveChange(ChangeRequest $cr, ?string $note, int $userId): ChangeRequest
    {
        self::requirePending($cr);

        return DB::transaction(function () use ($cr, $note, $userId) {
            $project = Project::whereKey($cr->project_id)->lockForUpdate()->firstOrFail();
            $cr->forceFill([
                'status' => 'approved', 'decision_note' => $note ?: null, 'decided_by' => $userId, 'decided_at' => now(),
                'budget_before' => $project->budget, 'due_before' => $project->due_date,
            ])->save();

            if ((float) $cr->cost_impact != 0.0) {
                $project->budget = round((float) $project->budget + (float) $cr->cost_impact, 2);
            }
            if ($cr->days_impact && $project->due_date) {
                $project->due_date = $project->due_date->copy()->addDays($cr->days_impact);
            }
            $project->save();

            ActivityLogger::log('projects.change_approved', $project, "اعتماد طلب التغيير {$cr->code()}: {$cr->title}", [
                'budget' => ['from' => $cr->budget_before === null ? null : (float) $cr->budget_before, 'to' => $project->budget === null ? null : (float) $project->budget],
                'due_date' => ['from' => $cr->due_before?->toDateString(), 'to' => $project->due_date?->toDateString()],
            ]);

            return $cr;
        });
    }

    public static function rejectChange(ChangeRequest $cr, string $note, int $userId): ChangeRequest
    {
        self::requirePending($cr);
        if (trim($note) === '') {
            throw ValidationException::withMessages(['decision_note' => 'اكتب سبب الرفض.']);
        }
        $cr->forceFill(['status' => 'rejected', 'decision_note' => $note, 'decided_by' => $userId, 'decided_at' => now()])->save();
        ActivityLogger::log('projects.change_rejected', $cr->project, "رفض طلب التغيير {$cr->code()}: {$cr->title}");

        return $cr;
    }

    /** Prefill for a new project from an accepted quote. @return array<string, mixed> */
    public static function fromQuote(Quote $quote): array
    {
        return [
            'client_id' => $quote->client_id,
            'quote_id' => $quote->id,
            'contract_id' => $quote->contracts()->whereIn('status', ['sent', 'active'])->whereNull('project_id')->value('id'),
            'name' => $quote->title,
            'currency' => $quote->currency,
            'budget' => $quote->totals()['net'],
            'description' => $quote->items->map(fn ($it) => '• '.$it->name)->implode("\n"),
        ];
    }

    /** Prefill for a new project from a contract. @return array<string, mixed> */
    public static function fromContract(Contract $contract): array
    {
        return [
            'client_id' => $contract->client_id,
            'contract_id' => $contract->id,
            'quote_id' => $contract->quote_id && ! Quote::whereKey($contract->quote_id)->whereNotNull('project_id')->exists() ? $contract->quote_id : null,
            'name' => $contract->title,
            'currency' => $contract->currency,
            'budget' => $contract->totalValue() ?: null,
            'start_date' => $contract->start_date?->toDateString(),
            'due_date' => $contract->end_date?->toDateString(),
            'description' => $contract->scope ?? '',
            'type' => $contract->isRetainer() ? 'social' : null,
        ];
    }

    /**
     * The quote must be accepted and the contract sent or signed, both of the project's client and not
     * already turned into another project.
     *
     * @param  array<string, mixed>  $data
     */
    private static function checkSources(array $data, ?Project $project): void
    {
        $clientId = (int) ($data['client_id'] ?? $project?->client_id);
        $errors = [];

        if (! empty($data['quote_id']) && (int) $data['quote_id'] !== (int) $project?->quote_id) {
            $quote = Quote::find($data['quote_id']);
            if (! $quote || (int) $quote->client_id !== $clientId) {
                $errors['quote_id'] = 'عرض السعر لا يتبع هذا العميل.';
            } elseif ($quote->status !== 'accepted') {
                $errors['quote_id'] = 'يُنشأ المشروع من عرض سعر مقبول فقط.';
            } elseif ($quote->project_id && (int) $quote->project_id !== (int) $project?->id) {
                $errors['quote_id'] = 'هذا العرض مرتبط بمشروع آخر.';
            }
        }
        if (! empty($data['contract_id']) && (int) $data['contract_id'] !== (int) $project?->contract_id) {
            $contract = Contract::find($data['contract_id']);
            if (! $contract || (int) $contract->client_id !== $clientId) {
                $errors['contract_id'] = 'العقد لا يتبع هذا العميل.';
            } elseif (! in_array($contract->status, ['sent', 'active'], true)) {
                $errors['contract_id'] = 'يُربط المشروع بعقد صادر أو ساري فقط.';
            } elseif ($contract->project_id && (int) $contract->project_id !== (int) $project?->id) {
                $errors['contract_id'] = 'هذا العقد مرتبط بمشروع آخر.';
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** Point the quote and contract back at the project; release ones that were unlinked. */
    private static function linkSources(Project $project): void
    {
        Quote::where('project_id', $project->id)->when($project->quote_id, fn ($q) => $q->whereKeyNot($project->quote_id))->update(['project_id' => null]);
        Contract::where('project_id', $project->id)->when($project->contract_id, fn ($q) => $q->whereKeyNot($project->contract_id))->update(['project_id' => null]);
        if ($project->quote_id) {
            Quote::whereKey($project->quote_id)->update(['project_id' => $project->id]);
        }
        if ($project->contract_id) {
            Contract::whereKey($project->contract_id)->update(['project_id' => $project->id]);
        }
    }

    private static function requirePending(ChangeRequest $cr): void
    {
        if ($cr->status !== 'pending') {
            throw ValidationException::withMessages(['status' => 'تم البت في هذا الطلب مسبقاً.']);
        }
    }
}
