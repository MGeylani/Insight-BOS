<?php

namespace App\Services\Tasks;

use App\Models\Client;
use App\Models\Project;
use App\Models\StatusScheme;
use App\Models\Task;
use App\Models\TaskList;
use App\Models\TaskStatus;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only place that creates lists, edits their statuses and hands out task ids per client.
 *
 * Every list owns a status scheme copied from a template when the list is created, so a list's
 * statuses can be changed without touching any other list.
 */
class TaskLists
{
    /** The agency's own client ("Insight Media"); created on first use. */
    public static function internalClient(): Client
    {
        $client = Client::where('is_internal', true)->orderBy('id')->first();
        if ($client) {
            return $client;
        }
        $cfg = config('tasks.internal_client');
        $client = new Client(['name' => $cfg['name'], 'type' => 'company', 'currency' => 'USD']);
        $client->forceFill(['is_internal' => true, 'task_prefix' => self::uniquePrefix($cfg['prefix'], null)])->save();

        return $client;
    }

    /** The client's task prefix, generated from its name the first time it is needed. */
    public static function prefixFor(Client $client): string
    {
        if (! $client->task_prefix) {
            $client->forceFill(['task_prefix' => self::uniquePrefix(self::suggestPrefix($client->name), $client->id)])->saveQuietly();
        }

        return $client->task_prefix;
    }

    /** "منبر ULFED" -> ULF; Arabic-only names use the first letters of their words. */
    public static function suggestPrefix(string $name): string
    {
        preg_match_all('/[A-Za-z]/', $name, $m);
        $latin = strtoupper(implode('', $m[0]));
        if (strlen($latin) >= 2) {
            return substr($latin, 0, 3);
        }
        $map = [
            'ا' => 'A', 'أ' => 'A', 'إ' => 'E', 'آ' => 'A', 'ب' => 'B', 'ت' => 'T', 'ث' => 'TH', 'ج' => 'J', 'ح' => 'H', 'خ' => 'KH',
            'د' => 'D', 'ذ' => 'TH', 'ر' => 'R', 'ز' => 'Z', 'س' => 'S', 'ش' => 'SH', 'ص' => 'S', 'ض' => 'D', 'ط' => 'T', 'ظ' => 'Z',
            'ع' => 'A', 'غ' => 'GH', 'ف' => 'F', 'ق' => 'Q', 'ك' => 'K', 'ل' => 'L', 'م' => 'M', 'ن' => 'N', 'ه' => 'H', 'و' => 'W',
            'ي' => 'Y', 'ى' => 'A', 'ة' => 'H', 'ء' => 'A', 'ؤ' => 'O', 'ئ' => 'E',
        ];
        $words = preg_split('/\s+/u', trim(preg_replace('/^(ال)/u', '', $name))) ?: [];
        $out = '';
        foreach ($words as $w) {
            $w = preg_replace('/^ال/u', '', $w);
            $first = mb_substr($w, 0, 1);
            $out .= $map[$first] ?? '';
            if (strlen($out) >= 3) {
                break;
            }
        }
        if (strlen($out) < 2 && isset($words[0])) {
            foreach (mb_str_split(preg_replace('/^ال/u', '', $words[0])) as $ch) {
                $out .= $map[$ch] ?? '';
                if (strlen($out) >= 3) {
                    break;
                }
            }
        }

        return substr($out ?: 'CL', 0, 3);
    }

    public static function uniquePrefix(string $base, ?int $exceptClientId): string
    {
        $base = substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($base)) ?: 'CL', 0, 6);
        $candidate = $base;
        for ($i = 2; Client::withTrashed()->where('task_prefix', $candidate)->when($exceptClientId, fn ($q) => $q->whereKeyNot($exceptClientId))->exists(); $i++) {
            $candidate = substr($base, 0, 5).$i;
        }

        return $candidate;
    }

    /** Next task number of the client (row locked so two tasks never share an id). */
    public static function nextSeq(Client $client): int
    {
        self::prefixFor($client);
        $row = Client::withTrashed()->whereKey($client->id)->lockForUpdate()->first();
        $next = (int) $row->task_seq + 1;
        Client::withTrashed()->whereKey($client->id)->update(['task_seq' => $next]);
        $client->task_seq = $next;

        return $next;
    }

    /** Copy a template (or the default one) into a new scheme owned by one list. */
    public static function copyScheme(?StatusScheme $template, string $name): StatusScheme
    {
        $template ??= StatusScheme::defaultTemplate();
        $scheme = StatusScheme::create(['name' => $name, 'is_template' => false]);
        $rows = $template?->statuses()->get()->map(fn (TaskStatus $s) => $s->only('name', 'color', 'group'))->all()
            ?: config('tasks.scheme_templates.agency.statuses');
        foreach (array_values($rows) as $i => $row) {
            $scheme->statuses()->create($row + ['sort' => $i + 1]);
        }

        return $scheme;
    }

    /**
     * @param  array{name: string, description?: string|null, project_milestone_id?: int|null, scheme_template_id?: int|null}  $data
     */
    public static function create(Client $client, ?Project $project, array $data, ?int $userId, bool $log = true): TaskList
    {
        if ($project && (int) $project->client_id !== (int) $client->id) {
            throw ValidationException::withMessages(['project_id' => 'المشروع لا يتبع هذا العميل.']);
        }
        if (! empty($data['project_milestone_id']) && (! $project || ! $project->milestones()->whereKey($data['project_milestone_id'])->exists())) {
            throw ValidationException::withMessages(['project_milestone_id' => 'المرحلة لا تتبع هذا المشروع.']);
        }

        return DB::transaction(function () use ($client, $project, $data, $userId, $log) {
            $template = ! empty($data['scheme_template_id'])
                ? StatusScheme::where('is_template', true)->find($data['scheme_template_id']) : null;
            $scheme = self::copyScheme($template, $data['name']);
            $list = TaskList::create([
                'client_id' => $client->id,
                'project_id' => $project?->id,
                'project_milestone_id' => $data['project_milestone_id'] ?? null,
                'status_scheme_id' => $scheme->id,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'sort' => (int) TaskList::where('client_id', $client->id)->where('project_id', $project?->id)->max('sort') + 1,
                'created_by' => $userId,
            ]);
            self::prefixFor($client);
            if ($log) {
                ActivityLogger::log('tasks.list_created', $project ?? $client, "إنشاء قائمة المهام «{$list->name}»");
            }

            return $list;
        });
    }

    /** Lists of a new project: one per template milestone, or the default lists without a template. */
    public static function createForProject(Project $project, ?int $userId): void
    {
        $client = $project->client()->first();
        if (! $client || $project->taskLists()->exists()) {
            return;
        }
        $milestones = $project->milestones()->get();
        if ($project->template && $milestones->isNotEmpty()) {
            foreach ($milestones as $m) {
                self::create($client, $project, ['name' => $m->title, 'project_milestone_id' => $m->id], $userId, false);
            }
        } else {
            foreach (config('tasks.default_project_lists') as $name) {
                self::create($client, $project, ['name' => $name], $userId, false);
            }
        }
    }

    /** A client's first list, created when a task is added at client level. */
    public static function defaultFor(Client $client, ?int $userId): TaskList
    {
        return TaskList::where('client_id', $client->id)->whereNull('project_id')->orderBy('sort')->first()
            ?? self::create($client, null, ['name' => 'مهام عامة'], $userId, false);
    }

    /** @param array<string, mixed> $data */
    public static function update(TaskList $list, array $data): TaskList
    {
        if (array_key_exists('project_milestone_id', $data) && $data['project_milestone_id']
            && (! $list->project_id || ! $list->project?->milestones()->whereKey($data['project_milestone_id'])->exists())) {
            throw ValidationException::withMessages(['project_milestone_id' => 'المرحلة لا تتبع مشروع هذه القائمة.']);
        }
        $oldMilestone = $list->project_milestone_id;
        $list->fill($data)->save();
        if (array_key_exists('project_milestone_id', $data) && (int) $oldMilestone !== (int) $list->project_milestone_id) {
            // Tasks of the list follow it to the new milestone.
            Task::where('task_list_id', $list->id)->update(['project_milestone_id' => $list->project_milestone_id]);
            Tasks::syncMilestone($oldMilestone);
            Tasks::syncMilestone($list->project_milestone_id);
        }
        if ($changes = ActivityLogger::diff($list)) {
            ActivityLogger::log('tasks.list_updated', $list->project ?? $list->client, "تعديل قائمة المهام «{$list->name}»", $changes);
        }

        return $list;
    }

    public static function delete(TaskList $list): void
    {
        if (Task::where('task_list_id', $list->id)->exists()) {
            throw ValidationException::withMessages(['list' => 'القائمة فيها مهام؛ انقلها أو أرشفها أولاً.']);
        }
        $list->linkedTasks()->detach();
        $list->delete();
        ActivityLogger::log('tasks.list_deleted', $list->project ?? $list->client, "حذف قائمة المهام «{$list->name}»");
    }

    /**
     * Replace the statuses of a list (or a template). Tasks on a removed status move to the status
     * named in $moveTo[oldId] (a new row's index as "new:<i>" or an existing id), else to the first
     * kept status of the same group, else to the first status.
     *
     * @param  list<array{id?: int|null, name: string, color?: string|null, group: string}>  $rows
     * @param  array<int|string, int|string>  $moveTo
     */
    public static function saveStatuses(StatusScheme $scheme, array $rows, array $moveTo = []): void
    {
        $rows = array_values(array_filter($rows, fn ($r) => trim((string) ($r['name'] ?? '')) !== ''));
        if (! $rows) {
            throw ValidationException::withMessages(['statuses' => 'أضف حالة واحدة على الأقل.']);
        }
        $names = array_map(fn ($r) => mb_strtolower(trim($r['name'])), $rows);
        if (count($names) !== count(array_unique($names))) {
            throw ValidationException::withMessages(['statuses' => 'أسماء الحالات يجب ألا تتكرر.']);
        }
        if (! array_filter($rows, fn ($r) => in_array($r['group'], TaskStatus::COMPLETE_GROUPS, true))) {
            throw ValidationException::withMessages(['statuses' => 'أضف حالة واحدة على الأقل من مجموعة «مكتملة» أو «مغلقة».']);
        }

        DB::transaction(function () use ($scheme, $rows, $moveTo) {
            $existing = $scheme->statuses()->get()->keyBy('id');
            $kept = [];
            $byIndex = [];
            foreach ($rows as $i => $r) {
                $attrs = ['name' => trim($r['name']), 'color' => $r['color'] ?? '#94a3b8', 'group' => $r['group'], 'sort' => $i + 1];
                $status = ! empty($r['id']) && $existing->has((int) $r['id'])
                    ? tap($existing[(int) $r['id']])->update($attrs)
                    : $scheme->statuses()->create($attrs);
                $kept[$status->id] = $status;
                $byIndex["new:$i"] = $status;
            }
            foreach ($existing as $id => $old) {
                if (isset($kept[$id])) {
                    continue;
                }
                $target = null;
                if (isset($moveTo[$id])) {
                    $target = $byIndex[$moveTo[$id]] ?? $kept[(int) $moveTo[$id]] ?? null;
                }
                $target ??= collect($kept)->first(fn ($s) => $s->group === $old->group) ?? reset($kept);
                Task::withTrashed()->where('status_id', $id)->update(['status_id' => $target->id]);
                $old->delete();
            }
            // Completion follows the (possibly regrouped) statuses.
            $complete = collect($kept)->filter->isComplete()->keys()->all();
            $taskIds = Task::whereIn('status_id', array_keys($kept));
            (clone $taskIds)->whereIn('status_id', $complete)->whereNull('completed_at')->update(['completed_at' => now()]);
            (clone $taskIds)->whereNotIn('status_id', $complete)->whereNotNull('completed_at')->update(['completed_at' => null]);
            $milestones = Task::whereIn('status_id', array_keys($kept))->whereNotNull('project_milestone_id')->distinct()->pluck('project_milestone_id');
            foreach ($milestones as $m) {
                Tasks::syncMilestone($m);
            }
        });
    }
}
