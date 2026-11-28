<?php

namespace App\Services\Finance;

use App\Models\Account;
use App\Models\Advance;
use App\Models\AdvanceReturn;
use App\Models\Expense;
use App\Models\Reimbursement;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorPayment;
use App\Models\VendorPaymentAllocation;
use App\Services\ActivityLogger;
use App\Services\NumberSequencer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Money going out that is not an expense paid on the spot:
 *  - vendor payments settling credit bills (one payment ↔ many bills, like client receipts),
 *  - reimbursing employees for approved out-of-pocket expenses,
 *  - advances / petty cash handed to employees and what comes back from them.
 * Every record is reversed, never deleted.
 */
class Payables
{
    // ── Vendor payments ───────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $data  vendor_id, account_id, date, amount, method, reference, notes
     * @param  list<array{expense_id: int, amount: float, payment_amount?: float|null}>  $allocations
     */
    public static function payVendor(array $data, array $allocations, int $userId): VendorPayment
    {
        $account = Account::findOrFail($data['account_id']);
        $date = self::pastDate($data['date'] ?? null);

        return DB::transaction(function () use ($data, $allocations, $account, $date, $userId) {
            $p = new VendorPayment([
                ...collect($data)->only('vendor_id', 'amount', 'method', 'reference', 'notes')->all(),
                'account_id' => $account->id, 'currency' => $account->currency, 'fx_rate' => Expenses::rate($account->currency, $date),
                'date' => $date->toDateString(), 'status' => 'posted', 'created_by' => $userId,
            ]);
            $p->number = NumberSequencer::next('vendor_payment');
            $p->save();
            ActivityLogger::log('finance.vendor_paid', $p, "دفعة مورد {$p->number}: {$p->amount} {$p->currency} إلى ".($p->vendor?->name ?? '—'));
            foreach ($allocations as $a) {
                if ((float) ($a['amount'] ?? 0) > 0) {
                    self::allocateBill($p, Expense::findOrFail($a['expense_id']), (float) $a['amount'], isset($a['payment_amount']) ? (float) $a['payment_amount'] : null);
                }
            }

            return $p->fresh();
        });
    }

    public static function allocateBill(VendorPayment $p, Expense $bill, float $amount, ?float $paymentAmount = null): VendorPaymentAllocation
    {
        $amount = round($amount, 2);
        if (! $p->isPosted()) {
            throw ValidationException::withMessages(['payment' => 'الدفعة معكوسة.']);
        }
        if ($bill->paid_by !== 'credit' || ! $bill->isApproved() || (int) $bill->vendor_id !== (int) $p->vendor_id) {
            throw ValidationException::withMessages(['allocations' => "{$bill->number} ليست فاتورة معتمدة لهذا المورد."]);
        }
        if ($amount <= 0 || $amount > $bill->owed() + 0.001) {
            throw ValidationException::withMessages(['allocations' => "المبلغ على {$bill->number} يتجاوز المستحق منها ({$bill->owed()} {$bill->currency})."]);
        }
        $paymentAmount = $bill->currency === $p->currency ? $amount : round($paymentAmount ?? Payments::convert($amount, $bill->currency, $p->currency, $p->date), 2);
        if ($paymentAmount <= 0 || $paymentAmount > $p->unallocated() + 0.001) {
            throw ValidationException::withMessages(['allocations' => "مجموع التسويات يتجاوز مبلغ الدفعة ({$p->unallocated()} {$p->currency})."]);
        }
        $a = $p->allocations()->create(['expense_id' => $bill->id, 'amount' => $amount, 'payment_amount' => $paymentAmount]);
        ActivityLogger::log('finance.bill_paid', $bill, "تسديد {$amount} {$bill->currency} من {$bill->number} بالدفعة {$p->number}");

        return $a;
    }

    public static function reverseVendorPayment(VendorPayment $p, string $reason, int $userId): VendorPayment
    {
        if (! $p->isPosted()) {
            throw ValidationException::withMessages(['reason' => 'الدفعة معكوسة مسبقاً.']);
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'اكتب سبب العكس.']);
        }

        return DB::transaction(function () use ($p, $reason) {
            $p->allocations()->delete();
            $p->forceFill(['status' => 'reversed', 'reversed_at' => now(), 'reverse_reason' => $reason])->save();
            ActivityLogger::log('finance.vendor_payment_reversed', $p, "عكس دفعة المورد {$p->number}", ['reason' => $reason]);

            return $p;
        });
    }

    /** Approved credit bills of a vendor still owed, oldest due first. @return Collection<int, array<string, mixed>> */
    public static function openBills(int $vendorId): Collection
    {
        return Expense::withPaid()->unpaidBills()->where('vendor_id', $vendorId)->orderBy('due_date')->orderBy('id')->get()
            ->map(fn (Expense $e) => [
                'id' => $e->id, 'number' => $e->number, 'vendor_ref' => $e->vendor_ref, 'description' => $e->description, 'currency' => $e->currency,
                'date' => $e->date->toDateString(), 'due_date' => $e->due_date?->toDateString(), 'amount' => (float) $e->amount, 'owed' => $e->owed(),
                'overdue' => $e->due_date && $e->due_date->lt(today()),
            ]);
    }

    /** What we owe each vendor, in USD at each bill's rate. @return array<int, float> vendor_id => usd */
    public static function owedByVendor(): array
    {
        return Expense::withPaid()->unpaidBills()->get()->groupBy('vendor_id')
            ->map(fn ($bills) => round($bills->sum(fn (Expense $e) => $e->owed() / max((float) $e->fx_rate, 0.000001)), 2))->all();
    }

    // ── Reimbursements ────────────────────────────────────────────────

    /**
     * Pay an employee back for approved out-of-pocket expenses in the account's currency.
     *
     * @param  list<int>  $expenseIds
     */
    public static function reimburse(int $userId, int $accountId, string $date, array $expenseIds, ?string $reference, int $actorId): Reimbursement
    {
        $account = Account::findOrFail($accountId);
        $date = self::pastDate($date);
        $expenses = Expense::approved()->where('paid_by', 'employee')->where('user_id', $userId)->whereNull('reimbursement_id')->whereIn('id', $expenseIds)->get();
        if ($expenses->isEmpty() || $expenses->count() !== count(array_unique($expenseIds))) {
            throw ValidationException::withMessages(['expense_ids' => 'اختر مصروفات معتمدة دفعها هذا الموظف ولم تُعوَّض بعد.']);
        }
        if ($expenses->pluck('currency')->unique()->all() !== [$account->currency]) {
            throw ValidationException::withMessages(['account_id' => "المصروفات المختارة يجب أن تكون بعملة الحساب ({$account->currency}). عوّض كل عملة من حساب بعملتها."]);
        }

        return DB::transaction(function () use ($userId, $account, $date, $expenses, $reference, $actorId) {
            $r = Reimbursement::create([
                'user_id' => $userId, 'account_id' => $account->id, 'date' => $date->toDateString(), 'amount' => round($expenses->sum('amount'), 2),
                'currency' => $account->currency, 'fx_rate' => Expenses::rate($account->currency, $date), 'reference' => $reference,
                'status' => 'posted', 'created_by' => $actorId,
            ]);
            Expense::whereIn('id', $expenses->pluck('id'))->update(['reimbursement_id' => $r->id]);
            ActivityLogger::log('finance.reimbursed', $r, "تعويض {$r->amount} {$r->currency} لـ ".($r->user?->name ?? '—')." عن {$expenses->count()} مصروف");

            return $r;
        });
    }

    public static function reverseReimbursement(Reimbursement $r): void
    {
        if ($r->status !== 'posted') {
            throw ValidationException::withMessages(['reimbursement' => 'التعويض معكوس مسبقاً.']);
        }
        DB::transaction(function () use ($r) {
            Expense::where('reimbursement_id', $r->id)->update(['reimbursement_id' => null]);
            $r->forceFill(['status' => 'reversed', 'reversed_at' => now()])->save();
            ActivityLogger::log('finance.reimbursement_reversed', $r, "عكس تعويض {$r->amount} {$r->currency} لـ ".($r->user?->name ?? '—'));
        });
    }

    /** Approved out-of-pocket expenses not yet paid back, grouped per employee and currency. @return Collection<int, array<string, mixed>> */
    public static function toReimburse(): Collection
    {
        return Expense::with(['user:id,name', 'category:id,name'])->approved()->where('paid_by', 'employee')->whereNull('reimbursement_id')
            ->orderBy('date')->get()->groupBy(fn (Expense $e) => $e->user_id.'|'.$e->currency)
            ->map(fn ($rows) => [
                'user' => $rows->first()->user?->only('id', 'name'), 'currency' => $rows->first()->currency,
                'total' => round($rows->sum('amount'), 2),
                'expenses' => $rows->map(fn (Expense $e) => [
                    'id' => $e->id, 'number' => $e->number, 'date' => $e->date->toDateString(), 'description' => $e->description,
                    'category' => $e->category?->name, 'amount' => (float) $e->amount,
                ])->values(),
            ])->values();
    }

    // ── Advances / petty cash ─────────────────────────────────────────

    /** @param array<string, mixed> $data user_id, kind, account_id, date, amount, notes */
    public static function giveAdvance(array $data, int $actorId): Advance
    {
        $account = Account::where('is_active', true)->findOrFail($data['account_id']);
        $date = self::pastDate($data['date'] ?? null);

        return DB::transaction(function () use ($data, $account, $date, $actorId) {
            $a = new Advance([
                ...collect($data)->only('user_id', 'kind', 'amount', 'notes')->all(),
                'account_id' => $account->id, 'currency' => $account->currency, 'fx_rate' => Expenses::rate($account->currency, $date),
                'date' => $date->toDateString(), 'status' => 'posted', 'created_by' => $actorId,
            ]);
            $a->number = NumberSequencer::next('advance');
            $a->save();
            ActivityLogger::log('finance.advance_given', $a, ($a->kind === 'petty_cash' ? 'عهدة نثرية ' : 'سلفة ')."{$a->number}: {$a->amount} {$a->currency} لـ ".($a->user?->name ?? '—'));

            return $a;
        });
    }

    /** Money back from an advance: into an account (same currency) or deducted (no account). @param array<string, mixed> $data */
    public static function returnAdvance(Advance $adv, array $data, int $actorId): AdvanceReturn
    {
        $amount = round((float) $data['amount'], 2);
        if (! $adv->isPosted() || $amount <= 0 || $amount > $adv->balance() + 0.001) {
            throw ValidationException::withMessages(['amount' => "المتبقي في العهدة {$adv->balance()} {$adv->currency}."]);
        }
        if (! empty($data['account_id']) && Account::findOrFail($data['account_id'])->currency !== $adv->currency) {
            throw ValidationException::withMessages(['account_id' => "اختر حساباً بعملة {$adv->currency}."]);
        }
        $r = $adv->returns()->create([
            'account_id' => $data['account_id'] ?: null, 'date' => self::pastDate($data['date'] ?? null)->toDateString(),
            'amount' => $amount, 'notes' => $data['notes'] ?? null, 'created_by' => $actorId,
        ]);
        ActivityLogger::log('finance.advance_returned', $adv, ($r->account_id ? 'إرجاع ' : 'خصم ')."{$amount} {$adv->currency} من {$adv->number}");

        return $r;
    }

    /** Void an advance entered by mistake: only while nothing was spent or returned from it. */
    public static function reverseAdvance(Advance $adv): void
    {
        if (! $adv->isPosted() || $adv->expenses()->whereIn('status', ['pending', 'approved'])->exists() || $adv->returns()->exists()) {
            throw ValidationException::withMessages(['advance' => 'على العهدة مصروفات أو مرتجعات؛ لا تُعكس.']);
        }
        $adv->forceFill(['status' => 'reversed', 'reversed_at' => now()])->save();
        ActivityLogger::log('finance.advance_reversed', $adv, "عكس {$adv->number}");
    }

    /** Open advances (balance > 0) of a user, for the expense form. @return Collection<int, array<string, mixed>> */
    public static function openAdvances(?int $userId = null): Collection
    {
        return Advance::with('user:id,name')->posted()->when($userId, fn ($q) => $q->where('user_id', $userId))->orderBy('date')->get()
            ->map(fn (Advance $a) => ['id' => $a->id, 'number' => $a->number, 'user_id' => $a->user_id, 'user' => $a->user?->name, 'currency' => $a->currency, 'balance' => $a->balance()])
            ->filter(fn ($a) => $a['balance'] > 0)->values();
    }

    private static function pastDate(?string $date): Carbon
    {
        $d = Carbon::parse($date ?? today());
        if ($d->isAfter(today())) {
            throw ValidationException::withMessages(['date' => 'لا يُسجَّل بتاريخ مستقبلي.']);
        }

        return $d;
    }

    /** Vendors for pickers. @return Collection<int, array<string, mixed>> */
    public static function vendorOptions(): Collection
    {
        return Vendor::orderBy('name')->get(['id', 'name', 'type']);
    }

    /** People an advance or expense can belong to. @return Collection<int, array<string, mixed>> */
    public static function people(): Collection
    {
        return User::where('is_active', true)->where('is_guest', false)->orderBy('name')->get(['id', 'name']);
    }
}
