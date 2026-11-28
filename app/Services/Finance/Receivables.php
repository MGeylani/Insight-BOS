<?php

namespace App\Services\Finance;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\TaskNotification;
use App\Services\ActivityLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What clients owe: the client statement, receivables aging and overdue reminders.
 * Amounts are reported in USD at each document's own rate (frozen at issue / payment date),
 * with the original amount and currency kept on every row.
 */
class Receivables
{
    /**
     * Client statement: invoices (debit), receipts (credit), refunds (debit), with a running USD balance.
     *
     * @return array<string, mixed>
     */
    public static function statement(Client $client, string $from, string $to): array
    {
        $rows = self::documents($client)->sortBy([['date', 'asc'], ['order', 'asc']])->values();
        $opening = round($rows->where('date', '<', $from)->sum(fn ($r) => $r['debit_usd'] - $r['credit_usd']), 2);
        $running = $opening;
        $period = $rows->filter(fn ($r) => $r['date'] >= $from && $r['date'] <= $to)->map(function ($r) use (&$running) {
            $running = round($running + $r['debit_usd'] - $r['credit_usd'], 2);

            return $r + ['balance_usd' => $running];
        })->values();

        return [
            'opening' => $opening,
            'rows' => $period,
            'closing' => $running,
            'debit' => round($period->sum('debit_usd'), 2),
            'credit' => round($period->sum('credit_usd'), 2),
            'open_invoices' => Payments::openInvoices($client->id),
            'credits' => Payments::credits($client),
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    private static function documents(Client $client): Collection
    {
        $invoices = Invoice::where('client_id', $client->id)->where('status', 'issued')->get()->map(fn (Invoice $i) => [
            'date' => $i->issue_date->toDateString(), 'order' => 1, 'type' => 'invoice', 'id' => $i->id, 'number' => $i->number,
            'label' => $i->title, 'amount' => $i->grandTotal(), 'currency' => $i->currency,
            'debit_usd' => $i->toBase($i->grandTotal()) ?? 0.0, 'credit_usd' => 0.0, 'url' => "/finance/invoices/{$i->id}",
        ]);
        $payments = Payment::posted()->where('client_id', $client->id)->get()->map(fn (Payment $p) => [
            'date' => $p->date->toDateString(), 'order' => 2, 'type' => $p->kind, 'id' => $p->id, 'number' => $p->number,
            'label' => $p->isReceipt() ? 'دفعة مستلمة' : 'مبلغ مردود', 'amount' => (float) $p->amount, 'currency' => $p->currency,
            'debit_usd' => $p->isReceipt() ? 0.0 : $p->amountInBase(), 'credit_usd' => $p->isReceipt() ? $p->amountInBase() : 0.0,
            'url' => "/finance/payments/{$p->id}",
        ]);

        return $invoices->concat($payments);
    }

    /**
     * Receivables aging per client, in USD: open balances by days past due.
     *
     * @return array{rows: list<array<string, mixed>>, totals: array<string, float>}
     */
    public static function aging(User $user, ?Carbon $at = null): array
    {
        $at ??= today();
        $buckets = array_keys(config('finance.aging_buckets'));
        $invoices = Invoice::visibleTo($user, 'finance.view')->with('client:id,name')->withPaid()->unpaid()->get();
        $rows = [];
        foreach ($invoices as $i) {
            $usd = $i->toBase($i->balance()) ?? 0.0;
            $days = $i->due_date ? (int) $i->due_date->diffInDays($at, false) : 0;
            $bucket = match (true) {
                $days <= 0 => 'current', $days <= 30 => '1_30', $days <= 60 => '31_60', $days <= 90 => '61_90', default => '90_plus',
            };
            $row = $rows[$i->client_id] ??= ['client' => $i->client?->only('id', 'name'), 'total' => 0.0, 'invoices' => 0] + array_fill_keys($buckets, 0.0);
            $row[$bucket] = round($row[$bucket] + $usd, 2);
            $row['total'] = round($row['total'] + $usd, 2);
            $row['invoices']++;
            $rows[$i->client_id] = $row;
        }
        $rows = collect($rows)->sortByDesc('total')->values();
        $totals = ['total' => round($rows->sum('total'), 2)];
        foreach ($buckets as $b) {
            $totals[$b] = round($rows->sum($b), 2);
        }

        return ['rows' => $rows->all(), 'totals' => $totals];
    }

    /**
     * Daily: remind the invoice owner on set days past due (1, 7, 14, 30, 60 by default). Once per day per invoice.
     */
    public static function remindOverdue(): int
    {
        $days = config('finance.overdue_reminder_days', [1, 7, 14, 30, 60]);
        $n = 0;
        Invoice::with('client:id,name')->withPaid()->unpaid()->whereDate('due_date', '<', today())
            ->where(fn ($q) => $q->whereNull('reminded_on')->orWhereDate('reminded_on', '<', today()))
            ->get()->each(function (Invoice $i) use ($days, &$n) {
                $late = (int) $i->due_date->diffInDays(today());
                if (! in_array($late, $days, true)) {
                    return;
                }
                $to = User::whereIn('id', array_filter([$i->owner_id, $i->created_by]))->where('is_active', true)->get();
                foreach ($to as $u) {
                    $u->notify(new TaskNotification([
                        'kind' => 'finance.invoice_overdue',
                        'title' => "فاتورة متأخرة {$late} يوماً: {$i->number}",
                        'body' => ($i->client?->name ?? '').' — المتبقي '.number_format($i->balance(), 2).' '.$i->currency,
                        'url' => "/finance/invoices/{$i->id}",
                    ]));
                }
                $i->forceFill(['reminded_on' => today()])->saveQuietly();
                $n++;
            });
        if ($n) {
            ActivityLogger::log('finance.overdue_reminders', null, "تذكير بـ {$n} فاتورة متأخرة");
        }

        return $n;
    }
}
