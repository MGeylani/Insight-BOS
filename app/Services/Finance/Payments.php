<?php

namespace App\Services\Finance;

use App\Models\Account;
use App\Models\Client;
use App\Models\ContractSchedule;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Services\ActivityLogger;
use App\Services\ExchangeRates;
use App\Services\NumberSequencer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only place that records money from or back to clients.
 *  - receive(): a receipt (RCPT-…) into an account, optionally settling invoices right away.
 *  - allocate() / deallocate(): settle invoices from a receipt's unallocated part (client credit) or undo it.
 *  - refund(): money back from a receipt's unallocated part.
 *  - reverse(): void a receipt or refund entered by mistake (allocations removed, invoices reopen).
 * After any change the invoice's contract instalments follow it: done when paid in full, pending otherwise.
 */
class Payments
{
    /**
     * @param  array<string, mixed>  $data  client_id, account_id, date, amount, method, reference, notes
     * @param  list<array{invoice_id: int, amount: float, payment_amount?: float|null}>  $allocations
     */
    public static function receive(array $data, array $allocations, int $userId): Payment
    {
        $account = Account::findOrFail($data['account_id']);
        $date = Carbon::parse($data['date'] ?? today());
        if ($date->isAfter(today())) {
            throw ValidationException::withMessages(['date' => 'لا تُسجَّل دفعة بتاريخ مستقبلي.']);
        }
        $rate = self::rate($account->currency, $date);

        return DB::transaction(function () use ($data, $allocations, $account, $date, $rate, $userId) {
            $p = new Payment([
                ...collect($data)->only('client_id', 'amount', 'method', 'reference', 'notes')->all(),
                'kind' => 'receipt', 'status' => 'posted', 'account_id' => $account->id, 'currency' => $account->currency, 'fx_rate' => $rate,
                'date' => $date->toDateString(), 'created_by' => $userId,
            ]);
            $p->number = NumberSequencer::next('receipt');
            $p->save();
            ActivityLogger::log('finance.payment_received', $p, "سند قبض {$p->number}: {$p->amount} {$p->currency} من ".($p->client?->name ?? '—'));
            foreach ($allocations as $a) {
                if ((float) ($a['amount'] ?? 0) > 0) {
                    self::allocate($p, Invoice::findOrFail($a['invoice_id']), (float) $a['amount'], isset($a['payment_amount']) ? (float) $a['payment_amount'] : null, $userId);
                }
            }

            return $p->fresh();
        });
    }

    /** Apply part of a receipt to an invoice. $amount is in the invoice's currency. */
    public static function allocate(Payment $p, Invoice $invoice, float $amount, ?float $paymentAmount, int $userId): PaymentAllocation
    {
        $amount = round($amount, 2);
        if (! $p->isReceipt() || ! $p->isPosted()) {
            throw ValidationException::withMessages(['payment' => 'التسوية من سندات القبض السارية فقط.']);
        }
        if (! $invoice->isIssued() || (int) $invoice->client_id !== (int) $p->client_id) {
            throw ValidationException::withMessages(['allocations' => 'الفاتورة '.($invoice->number ?? '').' ليست فاتورة صادرة لهذا العميل.']);
        }
        if ($amount <= 0 || $amount > $invoice->balance() + 0.001) {
            throw ValidationException::withMessages(['allocations' => "المبلغ على الفاتورة {$invoice->number} يتجاوز المتبقي منها ({$invoice->balance()} {$invoice->currency})."]);
        }
        $paymentAmount = $invoice->currency === $p->currency ? $amount : round($paymentAmount ?? self::convert($amount, $invoice->currency, $p->currency, $p->date), 2);
        if ($paymentAmount <= 0 || $paymentAmount > $p->unallocated() + 0.001) {
            throw ValidationException::withMessages(['allocations' => "مجموع التسويات يتجاوز غير المسوّى من السند ({$p->unallocated()} {$p->currency})."]);
        }

        return DB::transaction(function () use ($p, $invoice, $amount, $paymentAmount, $userId) {
            $a = $p->allocations()->create(['invoice_id' => $invoice->id, 'amount' => $amount, 'payment_amount' => $paymentAmount, 'created_by' => $userId]);
            ActivityLogger::log('finance.payment_allocated', $invoice, "تسوية {$amount} {$invoice->currency} من السند {$p->number}");
            self::syncInvoice($invoice);

            return $a;
        });
    }

    public static function deallocate(PaymentAllocation $a): void
    {
        DB::transaction(function () use ($a) {
            $invoice = $a->invoice;
            $a->delete();
            ActivityLogger::log('finance.payment_deallocated', $invoice, "فك تسوية {$a->amount} {$invoice?->currency} من السند {$a->payment?->number}");
            $invoice && self::syncInvoice($invoice);
        });
    }

    /** Return money to the client from a receipt's unallocated part (same currency). @param array<string, mixed> $data */
    public static function refund(Payment $receipt, array $data, int $userId): Payment
    {
        if (! $receipt->isReceipt() || ! $receipt->isPosted()) {
            throw ValidationException::withMessages(['amount' => 'الرد من سندات القبض السارية فقط.']);
        }
        $amount = round((float) $data['amount'], 2);
        if ($amount <= 0 || $amount > $receipt->unallocated() + 0.001) {
            throw ValidationException::withMessages(['amount' => "يُرد من غير المسوّى فقط ({$receipt->unallocated()} {$receipt->currency}). فك تسوية الفاتورة أولاً إن لزم."]);
        }
        $account = Account::findOrFail($data['account_id'] ?? $receipt->account_id);
        if ($account->currency !== $receipt->currency) {
            throw ValidationException::withMessages(['account_id' => "اختر حساباً بعملة {$receipt->currency}."]);
        }
        $date = Carbon::parse($data['date'] ?? today());

        return DB::transaction(function () use ($receipt, $data, $amount, $account, $date, $userId) {
            $r = new Payment([
                'kind' => 'refund', 'status' => 'posted', 'client_id' => $receipt->client_id, 'account_id' => $account->id, 'refund_of_id' => $receipt->id,
                'date' => $date->toDateString(), 'amount' => $amount, 'currency' => $account->currency, 'fx_rate' => self::rate($account->currency, $date),
                'method' => $data['method'] ?? $receipt->method, 'reference' => $data['reference'] ?? null, 'notes' => $data['notes'] ?? null, 'created_by' => $userId,
            ]);
            $r->number = NumberSequencer::next('receipt');
            $r->save();
            ActivityLogger::log('finance.payment_refunded', $receipt, "ردّ {$amount} {$r->currency} للعميل من السند {$receipt->number} ({$r->number})");

            return $r;
        });
    }

    /** Void a receipt or a refund entered by mistake. A receipt with posted refunds needs them reversed first. */
    public static function reverse(Payment $p, string $reason, int $userId): Payment
    {
        if (! $p->isPosted()) {
            throw ValidationException::withMessages(['reason' => 'السند معكوس مسبقاً.']);
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'اكتب سبب العكس.']);
        }
        if ($p->isReceipt() && $p->refunds()->posted()->exists()) {
            throw ValidationException::withMessages(['reason' => 'على السند مبالغ مردودة. اعكس سندات الرد أولاً.']);
        }

        return DB::transaction(function () use ($p, $reason, $userId) {
            $invoices = Invoice::whereIn('id', $p->allocations()->pluck('invoice_id'))->get();
            $p->allocations()->delete();
            $p->forceFill(['status' => 'reversed', 'reversed_at' => now(), 'reversed_by' => $userId, 'reverse_reason' => $reason])->save();
            ActivityLogger::log('finance.payment_reversed', $p, "عكس السند {$p->number}", ['reason' => $reason]);
            $invoices->each(fn (Invoice $i) => self::syncInvoice($i));

            return $p;
        });
    }

    /** Instalments billed by an invoice: done when it is paid in full, pending otherwise. */
    public static function syncInvoice(Invoice $invoice): void
    {
        $invoice->unsetRelation('allocations');
        $paid = $invoice->isIssued() && $invoice->balance() <= 0;
        ContractSchedule::where('invoice_id', $invoice->id)->where('status', $paid ? 'pending' : 'done')
            ->update($paid ? ['status' => 'done', 'done_at' => now()] : ['status' => 'pending', 'done_at' => null]);
    }

    /** Client credit: the unallocated part of the client's posted receipts. @return Collection<int, array<string, mixed>> */
    public static function credits(Client $client): Collection
    {
        return Payment::with('account:id,name')->posted()->where('kind', 'receipt')->where('client_id', $client->id)->orderBy('date')->get()
            ->map(fn (Payment $p) => ['id' => $p->id, 'number' => $p->number, 'date' => $p->date->toDateString(), 'currency' => $p->currency, 'unallocated' => $p->unallocated()])
            ->filter(fn ($c) => $c['unallocated'] > 0)->values();
    }

    /** Open invoices of a client, oldest due first, for allocating. @return Collection<int, array<string, mixed>> */
    public static function openInvoices(int $clientId): Collection
    {
        return Invoice::withPaid()->where('client_id', $clientId)->unpaid()->orderBy('due_date')->orderBy('id')->get()
            ->map(fn (Invoice $i) => [
                'id' => $i->id, 'number' => $i->number, 'title' => $i->title, 'currency' => $i->currency,
                'due_date' => $i->due_date?->toDateString(), 'total' => $i->grandTotal(), 'balance' => $i->balance(), 'overdue' => $i->isOverdue(),
            ]);
    }

    /** Convert between currencies with the rates of a date (through USD). */
    public static function convert(float $amount, string $from, string $to, Carbon|string|null $date = null): float
    {
        if ($from === $to) {
            return $amount;
        }
        $date = Carbon::parse($date ?? today());

        return round($amount / self::rate($from, $date) * self::rate($to, $date), 2);
    }

    private static function rate(string $currency, Carbon $date): float
    {
        $r = ExchangeRates::rateFor($currency, $date);
        if ($r === null) {
            throw ValidationException::withMessages(['account_id' => "لا يوجد سعر صرف مُدخل لعملة {$currency}. أدخله من الإعدادات أولاً."]);
        }

        return $r['rate'];
    }
}
