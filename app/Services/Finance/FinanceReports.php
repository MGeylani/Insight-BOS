<?php

namespace App\Services\Finance;

use App\Models\Account;
use App\Models\Advance;
use App\Models\Client;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\ServiceCategory;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\ExchangeRates;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Company finance reports, all in USD at each document's own rate:
 *   pnl()           profit & loss by month: revenue (issued invoices net of tax, by service category),
 *                   direct costs and overhead (approved expenses by category); labor cost shown for information.
 *   cashflow()      money in and out of the accounts by month and kind (transfers between accounts cancel out).
 *   profitability() per project, client, service category or employee.
 *   dashboard()     the numbers for the finance home page.
 */
class FinanceReports
{
    /** @var array<string, float> */
    private static array $rates = [];

    /** @return list<string> 'Y-m' from the month of $from to the month of $to */
    public static function months(string $from, string $to): array
    {
        $m = Carbon::parse($from)->startOfMonth();
        $end = Carbon::parse($to)->startOfMonth();
        $out = [];
        while ($m->lte($end) && count($out) < 60) {
            $out[] = $m->format('Y-m');
            $m->addMonth();
        }

        return $out;
    }

    // ── Profit & loss ─────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public static function pnl(string $from, string $to): array
    {
        $months = self::months($from, $to);
        $zero = array_fill_keys($months, 0.0);
        $revenue = [];
        foreach (self::invoices($from, $to) as $inv) {
            $m = $inv->issue_date->format('Y-m');
            foreach ($inv->items as $it) {
                $key = $it->category?->name ?? 'بلا تصنيف';
                $revenue[$key] ??= $zero;
                $revenue[$key][$m] = round($revenue[$key][$m] + self::itemUsd($inv, $it->net()), 2);
            }
        }
        $costs = ['direct' => [], 'overhead' => []];
        foreach (self::expenses($from, $to)->load('category:id,name,kind') as $e) {
            $m = $e->date->format('Y-m');
            $bucket = ($e->category?->kind === 'direct') ? 'direct' : 'overhead';
            $key = $e->category?->name ?? '—';
            $costs[$bucket][$key] ??= $zero;
            $costs[$bucket][$key][$m] = round($costs[$bucket][$key][$m] + $e->amountInBase(), 2);
        }
        ['direct' => $direct, 'overhead' => $overhead] = $costs;
        $labor = $zero;
        foreach (self::timeEntries($from, $to) as $t) {
            $m = $t->started_at->format('Y-m');
            if (isset($labor[$m]) && $t->cost_rate !== null) {
                $labor[$m] = round($labor[$m] + $t->minutes * (float) $t->cost_rate / 60, 2);
            }
        }
        $sum = fn (array $groups) => array_map(fn ($m) => round(array_sum(array_column($groups, $m)), 2), array_combine($months, $months));
        $rev = $sum($revenue);
        $dir = $sum($direct);
        $ovh = $sum($overhead);
        $gross = array_map(fn ($m) => round($rev[$m] - $dir[$m], 2), array_combine($months, $months));
        $net = array_map(fn ($m) => round($gross[$m] - $ovh[$m], 2), array_combine($months, $months));
        $rows = fn (array $g) => collect($g)->map(fn ($v, $k) => ['label' => $k, 'values' => $v, 'total' => round(array_sum($v), 2)])->sortByDesc('total')->values()->all();

        return [
            'months' => $months,
            'revenue' => $rows($revenue), 'direct' => $rows($direct), 'overhead' => $rows($overhead),
            'totals' => [
                'revenue' => ['values' => $rev, 'total' => round(array_sum($rev), 2)],
                'direct' => ['values' => $dir, 'total' => round(array_sum($dir), 2)],
                'gross' => ['values' => $gross, 'total' => round(array_sum($gross), 2)],
                'overhead' => ['values' => $ovh, 'total' => round(array_sum($ovh), 2)],
                'net' => ['values' => $net, 'total' => round(array_sum($net), 2)],
                'labor' => ['values' => $labor, 'total' => round(array_sum($labor), 2)],
            ],
        ];
    }

    // ── Cash flow ─────────────────────────────────────────────────────

    public const FLOW_LABELS = [
        'receipt' => 'تحصيل من العملاء', 'advance_return' => 'مرتجع عهد وسلف', 'transfer_in' => 'تحويل وارد',
        'refund' => 'ردّ مبالغ للعملاء', 'expense' => 'مصروفات مدفوعة', 'vendor_payment' => 'دفعات الموردين',
        'reimbursement' => 'تعويض الموظفين', 'advance' => 'عهد وسلف مسلّمة', 'transfer_out' => 'تحويل صادر',
    ];

    /** @return array<string, mixed> */
    public static function cashflow(string $from, string $to, ?int $accountId = null): array
    {
        self::$rates = [];
        $months = self::months($from, $to);
        $zero = array_fill_keys($months, 0.0);
        $accounts = Account::withTrashed()->when($accountId, fn ($q) => $q->whereKey($accountId))->get();
        $flows = [];
        foreach ($accounts as $a) {
            foreach (Accounts::movements($a, $from, $to) as $mv) {
                // Company-wide, moving money between our own accounts is neither in nor out.
                if (! $accountId && in_array($mv['kind'], ['transfer_in', 'transfer_out'], true)) {
                    continue;
                }
                $m = substr($mv['date'], 0, 7);
                if (! isset($zero[$m])) {
                    continue;
                }
                $flows[$mv['kind']] ??= $zero;
                $flows[$mv['kind']][$m] = round($flows[$mv['kind']][$m] + self::usd($mv['amount'], $a->currency, $mv['date']), 2);
            }
        }
        $in = $out = [];
        foreach (self::FLOW_LABELS as $kind => $label) {
            if (! isset($flows[$kind])) {
                continue;
            }
            $row = ['kind' => $kind, 'label' => $label, 'values' => array_map('abs', $flows[$kind]), 'total' => round(abs(array_sum($flows[$kind])), 2)];
            array_sum($flows[$kind]) >= 0 ? $in[] = $row : $out[] = $row;
        }
        $totIn = array_map(fn ($m) => round(array_sum(array_map(fn ($r) => $r['values'][$m], $in)), 2), array_combine($months, $months));
        $totOut = array_map(fn ($m) => round(array_sum(array_map(fn ($r) => $r['values'][$m], $out)), 2), array_combine($months, $months));
        $net = array_map(fn ($m) => round($totIn[$m] - $totOut[$m], 2), array_combine($months, $months));
        $opening = self::balancesUsd($accounts, Carbon::parse($from)->subDay());
        $closing = self::balancesUsd($accounts, Carbon::parse($to));

        return [
            'months' => $months, 'in' => $in, 'out' => $out,
            'totals' => [
                'in' => ['values' => $totIn, 'total' => round(array_sum($totIn), 2)],
                'out' => ['values' => $totOut, 'total' => round(array_sum($totOut), 2)],
                'net' => ['values' => $net, 'total' => round(array_sum($net), 2)],
            ],
            'opening' => $opening, 'closing' => $closing,
        ];
    }

    /** Sum of account balances in USD at the rates of that date. @param Collection<int, Account> $accounts */
    private static function balancesUsd(Collection $accounts, Carbon $at): float
    {
        return round($accounts->sum(fn (Account $a) => self::usd(Accounts::balance($a, $at), $a->currency, $at->toDateString())), 2);
    }

    // ── Profitability ─────────────────────────────────────────────────

    public const GROUPS = ['project' => 'المشاريع', 'client' => 'العملاء', 'service' => 'الخدمات', 'employee' => 'الموظفون'];

    /** @return array<string, mixed> */
    public static function profitability(string $group, string $from, string $to): array
    {
        $invoices = self::invoices($from, $to);
        $expenses = self::expenses($from, $to);
        $time = self::timeEntries($from, $to);

        $rows = match ($group) {
            'client', 'project' => self::byRecord($group, $invoices, $expenses, $time),
            'service' => self::byService($invoices, $expenses, $time),
            'employee' => self::byEmployee($invoices, $time),
            default => [],
        };
        $rows = collect($rows)->map(fn ($r) => $r + [
            'profit' => $p = round($r['revenue'] - $r['expenses'] - $r['labor'], 2),
            'margin' => $r['revenue'] > 0 ? round($p / $r['revenue'] * 100, 1) : null,
        ])->sortByDesc('revenue')->values();

        return [
            'group' => $group,
            'rows' => $rows->all(),
            'totals' => [
                'revenue' => round($rows->sum('revenue'), 2), 'expenses' => round($rows->sum('expenses'), 2),
                'labor' => round($rows->sum('labor'), 2), 'profit' => round($rows->sum('profit'), 2),
                'minutes' => (int) $rows->sum('minutes'),
            ],
        ];
    }

    /** Projects or clients: revenue, approved expenses and labor recorded against each. */
    private static function byRecord(string $group, Collection $invoices, Collection $expenses, Collection $time): array
    {
        $col = $group.'_id';
        $rows = [];
        $add = function ($id, string $field, float $v) use (&$rows) {
            if (! $id) {
                return;
            }
            $rows[$id] ??= ['id' => $id, 'revenue' => 0.0, 'expenses' => 0.0, 'labor' => 0.0, 'minutes' => 0];
            $rows[$id][$field] = round($rows[$id][$field] + $v, 2);
        };
        foreach ($invoices as $i) {
            $add($i->$col, 'revenue', Profitability::netUsd($i));
        }
        foreach ($expenses as $e) {
            $add($e->$col, 'expenses', $e->amountInBase());
        }
        foreach ($time as $t) {
            $add($t->$col, 'labor', $t->cost_rate !== null ? $t->minutes * (float) $t->cost_rate / 60 : 0.0);
            if ($t->$col) {
                $rows[$t->$col]['minutes'] += (int) $t->minutes;
            }
        }
        $names = $group === 'project'
            ? Project::withTrashed()->whereIn('id', array_keys($rows))->get()->mapWithKeys(fn ($p) => [$p->id => $p->number.' · '.$p->name])
            : Client::withTrashed()->whereIn('id', array_keys($rows))->pluck('name', 'id');

        return array_map(fn ($r) => $r + ['label' => $names[$r['id']] ?? '—', 'url' => $group === 'project' ? "/projects/{$r['id']}" : "/crm/clients/{$r['id']}"], array_values($rows));
    }

    /**
     * Service categories: revenue from invoice lines; a project's costs (expenses + labor in the period) are shared
     * between the categories it invoiced, in proportion to their revenue. Costs of projects with no revenue stay unallocated.
     */
    private static function byService(Collection $invoices, Collection $expenses, Collection $time): array
    {
        $rows = [];
        $projectRev = [];
        foreach ($invoices as $i) {
            foreach ($i->items as $it) {
                $key = $it->service_category_id ?: 0;
                $usd = self::itemUsd($i, $it->net());
                $rows[$key] ??= ['id' => $key, 'label' => $it->category?->name ?? 'بلا تصنيف', 'revenue' => 0.0, 'expenses' => 0.0, 'labor' => 0.0, 'minutes' => 0];
                $rows[$key]['revenue'] = round($rows[$key]['revenue'] + $usd, 2);
                if ($i->project_id) {
                    $projectRev[$i->project_id][$key] = ($projectRev[$i->project_id][$key] ?? 0) + $usd;
                }
            }
        }
        $share = function (?int $projectId, string $field, float $v, int $minutes = 0) use (&$rows, $projectRev) {
            $split = $projectId ? ($projectRev[$projectId] ?? []) : [];
            $total = array_sum($split);
            if ($total <= 0) {
                return;
            }
            foreach ($split as $key => $rev) {
                $rows[$key][$field] = round($rows[$key][$field] + $v * $rev / $total, 2);
                $rows[$key]['minutes'] += (int) round($minutes * $rev / $total);
            }
        };
        foreach ($expenses as $e) {
            $share($e->project_id, 'expenses', $e->amountInBase());
        }
        foreach ($time as $t) {
            $share($t->project_id, 'labor', $t->cost_rate !== null ? $t->minutes * (float) $t->cost_rate / 60 : 0.0, (int) $t->minutes);
        }

        return array_values($rows);
    }

    /** Employees: hours and their cost; revenue credited from each project's revenue by their share of its hours. */
    private static function byEmployee(Collection $invoices, Collection $time): array
    {
        $projectRev = [];
        foreach ($invoices as $i) {
            if ($i->project_id) {
                $projectRev[$i->project_id] = ($projectRev[$i->project_id] ?? 0) + Profitability::netUsd($i);
            }
        }
        $projectMin = $time->whereNotNull('project_id')->groupBy('project_id')->map->sum('minutes');
        $rows = [];
        foreach ($time as $t) {
            $id = $t->user_id;
            $rows[$id] ??= ['id' => $id, 'revenue' => 0.0, 'expenses' => 0.0, 'labor' => 0.0, 'minutes' => 0, 'billable_minutes' => 0];
            $rows[$id]['minutes'] += (int) $t->minutes;
            $rows[$id]['billable_minutes'] += $t->billable ? (int) $t->minutes : 0;
            $rows[$id]['labor'] = round($rows[$id]['labor'] + ($t->cost_rate !== null ? $t->minutes * (float) $t->cost_rate / 60 : 0.0), 2);
            if ($t->project_id && ($projectMin[$t->project_id] ?? 0) > 0) {
                $rows[$id]['revenue'] = round($rows[$id]['revenue'] + ($projectRev[$t->project_id] ?? 0) * $t->minutes / $projectMin[$t->project_id], 2);
            }
        }
        $names = User::withTrashed()->whereIn('id', array_keys($rows))->pluck('name', 'id');

        return array_map(fn ($r) => $r + ['label' => $names[$r['id']] ?? '—', 'url' => null], array_values($rows));
    }

    // ── Dashboard ─────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public static function dashboard(User $user): array
    {
        self::$rates = [];
        $today = today();
        $cash = Account::where('is_active', true)->get()->map(fn (Account $a) => [
            'id' => $a->id, 'name' => $a->name, 'currency' => $a->currency,
            'balance' => $b = Accounts::balance($a), 'usd' => self::usd($b, $a->currency, $today->toDateString()),
        ]);
        $aging = Receivables::aging($user);
        $overdue = $aging['totals']['total'] - ($aging['totals']['current'] ?? 0);
        $from = $today->copy()->subMonths(11)->startOfMonth()->toDateString();
        $pnl = self::pnl($from, $today->toDateString());
        $thisM = $today->format('Y-m');
        $lastM = $today->copy()->subMonthNoOverflow()->format('Y-m');
        $pick = fn (string $k, string $m) => $pnl['totals'][$k]['values'][$m] ?? 0.0;

        return [
            'cash' => ['total' => round($cash->sum('usd'), 2), 'accounts' => $cash->values()->all()],
            'receivables' => $aging['totals']['total'], 'overdue' => round($overdue, 2),
            'top_overdue' => collect($aging['rows'])->map(fn ($r) => ['client' => $r['client'], 'overdue' => round($r['total'] - ($r['current'] ?? 0), 2), 'total' => $r['total']])
                ->filter(fn ($r) => $r['overdue'] > 0)->sortByDesc('overdue')->take(5)->values()->all(),
            'payables' => round(array_sum(Payables::owedByVendor()), 2),
            'to_reimburse' => round(Expense::approved()->where('paid_by', 'employee')->whereNull('reimbursement_id')->get()->sum(fn ($e) => $e->amountInBase()), 2),
            'advances' => round(Advance::posted()->get()->sum(fn (Advance $a) => $a->balance() / max((float) $a->fx_rate, 0.000001)), 2),
            'pending_expenses' => Expense::where('status', 'pending')->count(),
            'month' => [
                'revenue' => $pick('revenue', $thisM), 'cost' => round($pick('direct', $thisM) + $pick('overhead', $thisM), 2), 'net' => $pick('net', $thisM),
                'last_revenue' => $pick('revenue', $lastM), 'last_net' => $pick('net', $lastM),
            ],
            'trend' => array_map(fn ($m) => [
                'month' => $m, 'revenue' => $pick('revenue', $m), 'cost' => round($pick('direct', $m) + $pick('overhead', $m), 2), 'net' => $pick('net', $m),
            ], $pnl['months']),
            'budgets' => \App\Models\Budget::with('lines')->whereDate('period_start', '<=', $today)->whereDate('period_end', '>=', $today)->get()
                ->map(fn ($b) => [...$b->only('id', 'name', 'alert_percent'), ...collect(Budgets::report($b))->only('planned', 'actual', 'percent')->all()])
                ->filter(fn ($b) => $b['percent'] >= $b['alert_percent'])->sortByDesc('percent')->values()->all(),
        ];
    }

    // ── Sources ───────────────────────────────────────────────────────

    /** @return Collection<int, Invoice> */
    private static function invoices(string $from, string $to): Collection
    {
        return Invoice::with('items.category:id,name')->where('status', 'issued')
            ->whereDate('issue_date', '>=', $from)->whereDate('issue_date', '<=', $to)->get();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Expense> */
    private static function expenses(string $from, string $to)
    {
        return Expense::approved()->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->get();
    }

    /** @return Collection<int, TimeEntry> */
    private static function timeEntries(string $from, string $to): Collection
    {
        return TimeEntry::whereNotNull('ended_at')->whereDate('started_at', '>=', $from)->whereDate('started_at', '<=', $to)->get();
    }

    /** An invoice line's net in USD at the invoice's frozen rate. */
    private static function itemUsd(Invoice $i, float $net): float
    {
        return round($net / max((float) ($i->fx_rate ?: 1), 0.000001), 2);
    }

    private static function usd(float $amount, string $currency, string $date): float
    {
        if ($amount == 0.0) {
            return 0.0;
        }
        $key = $currency.'|'.$date;
        self::$rates[$key] ??= ExchangeRates::rateFor($currency, Carbon::parse($date))['rate'] ?? 1.0;

        return round($amount / max(self::$rates[$key], 0.000001), 2);
    }
}
