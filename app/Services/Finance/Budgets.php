<?php

namespace App\Services\Finance;

use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\Expense;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Notifications\TaskNotification;
use App\Services\ActivityLogger;
use App\Services\Tasks\TimeTracking;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Budgets: planned spend in USD per expense category (or labor), for a project, a cost center or the company,
 * over a period. Actuals are never stored: approved expenses (USD at each expense's rate) and logged hours' cost.
 * Alerts go to the owner, creator and project manager when a line reaches alert_percent, and again past 100%.
 */
class Budgets
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{kind: string, category_id?: int|null, planned: float}>  $lines
     */
    public static function save(Budget $b, array $data, array $lines, User $by): Budget
    {
        if (! empty($data['project_id']) && ! empty($data['cost_center_id'])) {
            throw ValidationException::withMessages(['cost_center_id' => 'الميزانية لمشروع أو لمركز تكلفة، لا الاثنين.']);
        }
        $keys = [];
        foreach ($lines as $l) {
            $key = $l['kind'] === 'labor' ? 'labor' : 'c'.($l['category_id'] ?? '');
            if ($key === 'c') {
                throw ValidationException::withMessages(['lines' => 'اختر تصنيف كل بند.']);
            }
            if (isset($keys[$key])) {
                throw ValidationException::withMessages(['lines' => 'كل تصنيف مرة واحدة في الميزانية.']);
            }
            $keys[$key] = true;
        }
        if (! $lines) {
            throw ValidationException::withMessages(['lines' => 'أضف بنداً واحداً على الأقل.']);
        }
        $new = ! $b->exists;

        return DB::transaction(function () use ($b, $data, $lines, $by, $new) {
            $b->fill($data + ($new ? ['created_by' => $by->id, 'owner_id' => $data['owner_id'] ?? $by->id] : []))->save();
            $old = $b->lines()->get()->keyBy(fn (BudgetLine $l) => $l->kind === 'labor' ? 'labor' : 'c'.$l->category_id);
            $b->lines()->delete();
            foreach (array_values($lines) as $i => $l) {
                $key = $l['kind'] === 'labor' ? 'labor' : 'c'.$l['category_id'];
                $prev = $old[$key] ?? null;
                $b->lines()->create([
                    'kind' => $l['kind'] === 'labor' ? 'labor' : 'category', 'category_id' => $l['kind'] === 'labor' ? null : $l['category_id'],
                    'planned' => round((float) $l['planned'], 2), 'sort' => $i,
                    // Keep the alert state unless the plan grew (then it may warn again).
                    'alert_level' => $prev && (float) $prev->planned >= (float) $l['planned'] ? $prev->alert_level : 0,
                ]);
            }
            ActivityLogger::log($new ? 'finance.budget_created' : 'finance.budget_updated', $b, ($new ? 'إنشاء' : 'تعديل')." ميزانية «{$b->name}»");

            return $b;
        });
    }

    /** Approved expenses inside a budget's scope and period. */
    public static function expensesQuery(Budget $b): Builder
    {
        return Expense::approved()
            ->whereDate('date', '>=', $b->period_start)->whereDate('date', '<=', $b->period_end)
            ->when($b->project_id, fn ($q) => $q->where('project_id', $b->project_id))
            ->when($b->cost_center_id, fn ($q) => $q->where('cost_center_id', $b->cost_center_id));
    }

    /** USD per category (approved expenses in scope and period). @return array<int, float> */
    private static function byCategory(Budget $b): array
    {
        return self::expensesQuery($b)->get(['category_id', 'amount', 'fx_rate'])->groupBy('category_id')
            ->map(fn ($rows) => round($rows->sum(fn (Expense $e) => $e->amountInBase()), 2))->all();
    }

    /** Cost of logged hours in scope and period (projects and the whole company; cost centers have none). */
    private static function labor(Budget $b): float
    {
        if ($b->cost_center_id) {
            return 0.0;
        }
        $q = TimeEntry::query()->whereDate('started_at', '>=', $b->period_start)->whereDate('started_at', '<=', $b->period_end)
            ->when($b->project_id, fn ($q) => $q->where('project_id', $b->project_id));

        return TimeTracking::totals($q)['cost'];
    }

    /**
     * Lines with actuals, plus what was spent in scope on categories without a line.
     *
     * @return array<string, mixed>
     */
    public static function report(Budget $b): array
    {
        $b->loadMissing('lines.category:id,name,kind');
        $cats = self::byCategory($b);
        $labor = null;
        $lines = $b->lines->map(function (BudgetLine $l) use ($cats, $b, &$labor) {
            $actual = $l->kind === 'labor' ? ($labor ??= self::labor($b)) : ($cats[$l->category_id] ?? 0.0);
            $planned = (float) $l->planned;

            return [
                'id' => $l->id, 'kind' => $l->kind, 'category' => $l->category?->only('id', 'name', 'kind'),
                'planned' => $planned, 'actual' => $actual, 'variance' => round($planned - $actual, 2),
                'percent' => $planned > 0 ? round($actual / $planned * 100, 1) : ($actual > 0 ? 999.0 : 0.0),
            ];
        });
        $covered = $b->lines->where('kind', 'category')->pluck('category_id')->all();
        $unbudgeted = round(collect($cats)->except($covered)->sum(), 2);
        $planned = round($lines->sum('planned'), 2);
        $actual = round($lines->sum('actual'), 2);

        return [
            'lines' => $lines->values()->all(),
            'planned' => $planned, 'actual' => $actual, 'variance' => round($planned - $actual, 2),
            'percent' => $planned > 0 ? round($actual / $planned * 100, 1) : 0.0,
            'unbudgeted' => $unbudgeted,
            'elapsed' => self::elapsed($b),
        ];
    }

    /** Share of the period gone by (to compare spend against time). */
    private static function elapsed(Budget $b): float
    {
        $days = max(1, $b->period_start->diffInDays($b->period_end) + 1);
        $gone = today()->lt($b->period_start) ? 0 : min($days, $b->period_start->diffInDays(min(today(), $b->period_end)) + 1);

        return round($gone / $days * 100, 1);
    }

    /** After an expense is approved: check the budgets it falls into. */
    public static function checkExpense(Expense $e): int
    {
        $budgets = Budget::with('lines')->whereDate('period_start', '<=', $e->date)->whereDate('period_end', '>=', $e->date)
            ->where(fn ($q) => $q->where(fn ($q) => $q->whereNull('project_id')->whereNull('cost_center_id'))
                ->orWhere(fn ($q) => $e->project_id ? $q->where('project_id', $e->project_id) : $q->whereRaw('1 = 0'))
                ->orWhere(fn ($q) => $e->cost_center_id ? $q->where('cost_center_id', $e->cost_center_id) : $q->whereRaw('1 = 0')))
            ->whereHas('lines', fn ($q) => $q->where('category_id', $e->category_id))->get();

        return $budgets->sum(fn (Budget $b) => self::alert($b));
    }

    /** Daily (finance:budgets): every running budget, which also catches labor lines. */
    public static function checkAll(): int
    {
        return Budget::whereDate('period_start', '<=', today())->whereDate('period_end', '>=', today()->subDays(7))->get()
            ->sum(fn (Budget $b) => self::alert($b));
    }

    /** Send alerts for lines that crossed alert_percent or 100% since the last alert. Returns the number of alerts. */
    public static function alert(Budget $b): int
    {
        $report = self::report($b);
        $sent = 0;
        foreach ($report['lines'] as $row) {
            $line = $b->lines->firstWhere('id', $row['id']);
            $level = $row['percent'] >= 100 ? 100 : ($row['percent'] >= $b->alert_percent ? (int) $b->alert_percent : 0);
            if ($level === 0 || $level <= $line->alert_level) {
                continue;
            }
            $line->forceFill(['alert_level' => $level])->save();
            $name = $row['kind'] === 'labor' ? 'ساعات العمل' : ($row['category']['name'] ?? '');
            $title = $level >= 100 ? "تجاوز الميزانية: {$b->name} — {$name}" : "اقتربت من الميزانية: {$b->name} — {$name}";
            $body = 'المصروف '.number_format($row['actual'], 2).' من '.number_format($row['planned'], 2)." USD ({$row['percent']}%)";
            foreach (self::watchers($b) as $u) {
                $u->notify(new TaskNotification(['kind' => 'finance.budget_alert', 'title' => $title, 'body' => $body, 'url' => "/finance/budgets/{$b->id}"]));
            }
            ActivityLogger::log('finance.budget_alert', $b, $title);
            $sent++;
        }

        return $sent;
    }

    /** @return \Illuminate\Support\Collection<int, User> */
    private static function watchers(Budget $b)
    {
        $ids = array_filter([$b->owner_id, $b->created_by, $b->project?->manager_id]);

        return User::whereIn('id', array_unique($ids))->where('is_active', true)->get();
    }

    /** Finance (edit, all) manages every budget; a project's budget also by whoever manages that project's budget. */
    public static function canManage(User $u, ?Project $project): bool
    {
        if ($u->permissionScope('finance.edit') === 'all') {
            return true;
        }

        return $project !== null && $project->isVisibleTo($u, 'projects.manage_budget');
    }

    public static function canView(User $u, Budget $b): bool
    {
        return $u->permissionScope('finance.view') === 'all' || self::canManage($u, $b->project) || (int) $b->owner_id === $u->id;
    }
}
