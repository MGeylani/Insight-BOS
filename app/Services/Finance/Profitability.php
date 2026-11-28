<?php

namespace App\Services\Finance;

use App\Models\Expense;
use App\Models\Invoice;
use App\Models\PaymentAllocation;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Services\Tasks\TimeTracking;
use Illuminate\Database\Eloquent\Builder;

/**
 * Profit = revenue − costs, all in USD at each document's own rate:
 *   revenue     = issued invoices, net of tax (what the agency earns; tax is passed on)
 *   collected   = what clients paid against those invoices
 *   expenses    = approved expenses
 *   labor       = logged hours × the person's hourly cost at the time
 * Used for a project here, and per client / service / employee by the reports (step 5).
 */
class Profitability
{
    /** @return array<string, mixed> */
    public static function project(Project $p): array
    {
        $invoices = Invoice::with('items')->where('project_id', $p->id)->where('status', 'issued')->get();
        $expenses = Expense::with('category:id,name,kind')->approved()->where('project_id', $p->id)->get();
        $labor = TimeTracking::totals(TimeEntry::where('project_id', $p->id));

        return self::summarize($invoices, $expenses, $labor) + [
            'value' => $p->budgetInBase(), // agreed value with the client
            'by_category' => $expenses->groupBy('category_id')->map(fn ($rows) => [
                'name' => $rows->first()->category?->name, 'kind' => $rows->first()->category?->kind,
                'usd' => round($rows->sum(fn (Expense $e) => $e->amountInBase()), 2),
            ])->sortByDesc('usd')->values()->all(),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Invoice>  $invoices
     * @param  \Illuminate\Support\Collection<int, Expense>  $expenses
     * @param  array{cost: float, minutes: int, uncosted_minutes: int}  $labor
     * @return array<string, mixed>
     */
    public static function summarize($invoices, $expenses, array $labor): array
    {
        $revenue = round($invoices->sum(fn (Invoice $i) => self::netUsd($i)), 2);
        $invoiced = round($invoices->sum(fn (Invoice $i) => $i->totalInBase() ?? 0), 2);
        $collected = round(PaymentAllocation::whereIn('invoice_id', $invoices->pluck('id'))->with('invoice:id,fx_rate')->get()
            ->sum(fn ($a) => (float) $a->amount / max((float) ($a->invoice?->fx_rate ?: 1), 0.000001)), 2);
        $exp = round($expenses->sum(fn (Expense $e) => $e->amountInBase()), 2);
        $cost = round($exp + $labor['cost'], 2);
        $profit = round($revenue - $cost, 2);

        return [
            'revenue' => $revenue, 'invoiced' => $invoiced, 'collected' => $collected, 'outstanding' => round($invoiced - $collected, 2),
            'expenses' => $exp, 'labor' => $labor['cost'], 'labor_minutes' => $labor['minutes'], 'uncosted_minutes' => $labor['uncosted_minutes'],
            'cost' => $cost, 'profit' => $profit, 'margin' => $revenue > 0 ? round($profit / $revenue * 100, 1) : null,
        ];
    }

    /** Invoice revenue in USD, net of tax. */
    public static function netUsd(Invoice $i): float
    {
        $t = $i->totals();
        $net = $i->total !== null ? (float) $i->total - $t['tax'] : $t['net'];

        return $i->toBase($net) ?? 0.0;
    }

    /** Approved expenses of a scope. */
    public static function expenses(?callable $scope = null): Builder
    {
        return Expense::approved()->when($scope, $scope);
    }
}
