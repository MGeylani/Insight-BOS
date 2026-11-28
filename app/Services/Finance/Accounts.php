<?php

namespace App\Services\Finance;

use App\Models\Account;
use App\Models\AccountTransfer;
use App\Models\Payment;
use App\Services\ActivityLogger;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cash and bank accounts. Balances are never stored: opening balance + every movement up to a date.
 * Movements: receipts and refunds, transfers (later steps add expenses and vendor payments in movements()).
 */
class Accounts
{
    public static function balance(Account $account, ?CarbonInterface $at = null): float
    {
        $to = $at?->toDateString();

        return round((float) $account->opening_balance + self::movements($account, null, $to)->sum('amount'), 2);
    }

    /**
     * Every movement of an account, oldest first: ['date', 'amount' (+in / -out), 'kind', 'label', 'url', 'ref'].
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function movements(Account $account, ?string $from = null, ?string $to = null): Collection
    {
        $range = fn ($q, $col = 'date') => $q->when($from, fn ($q) => $q->whereDate($col, '>=', $from))->when($to, fn ($q) => $q->whereDate($col, '<=', $to));

        $payments = $range(Payment::with('client:id,name')->posted()->where('account_id', $account->id))->get()
            ->map(fn (Payment $p) => [
                'date' => $p->date->toDateString(), 'amount' => $p->isReceipt() ? (float) $p->amount : -(float) $p->amount,
                'kind' => $p->kind, 'label' => ($p->isReceipt() ? 'قبض من ' : 'ردّ إلى ').($p->client?->name ?? '—'),
                'ref' => $p->number, 'url' => "/finance/payments/{$p->id}", 'sort' => $p->id,
            ]);
        $out = $range(AccountTransfer::with('to:id,name')->whereNull('reversed_at')->where('from_account_id', $account->id))->get()
            ->map(fn (AccountTransfer $t) => [
                'date' => $t->date->toDateString(), 'amount' => -(float) $t->amount, 'kind' => 'transfer_out',
                'label' => 'تحويل إلى '.$t->to?->name, 'ref' => $t->reference, 'url' => null, 'sort' => $t->id, 'transfer_id' => $t->id,
            ]);
        $in = $range(AccountTransfer::with('from:id,name')->whereNull('reversed_at')->where('to_account_id', $account->id))->get()
            ->map(fn (AccountTransfer $t) => [
                'date' => $t->date->toDateString(), 'amount' => (float) $t->to_amount, 'kind' => 'transfer_in',
                'label' => 'تحويل من '.$t->from?->name, 'ref' => $t->reference, 'url' => null, 'sort' => $t->id, 'transfer_id' => $t->id,
            ]);
        $all = $payments->concat($out)->concat($in);

        return $all->sortBy([['date', 'asc'], ['sort', 'asc']])->values();
    }

    /** Account statement for a period: opening, rows with running balance, closing. @return array<string, mixed> */
    public static function statement(Account $account, string $from, string $to): array
    {
        $opening = self::balance($account, Carbon::parse($from)->subDay());
        $running = $opening;
        $rows = self::movements($account, $from, $to)->map(function ($m) use (&$running) {
            $running = round($running + $m['amount'], 2);

            return $m + ['balance' => $running];
        });

        return [
            'opening' => $opening, 'rows' => $rows->values(), 'closing' => $running,
            'in' => round($rows->where('amount', '>', 0)->sum('amount'), 2), 'out' => round(-$rows->where('amount', '<', 0)->sum('amount'), 2),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function save(Account $account, array $data, int $userId): Account
    {
        if ($account->exists && isset($data['currency']) && $data['currency'] !== $account->currency && self::movements($account)->isNotEmpty()) {
            throw ValidationException::withMessages(['currency' => 'لا تُغيَّر عملة حساب عليه حركات.']);
        }
        $new = ! $account->exists;
        $account->fill($data + ($new ? ['created_by' => $userId] : []))->save();
        ActivityLogger::log($new ? 'finance.account_created' : 'finance.account_updated', $account, ($new ? 'إنشاء' : 'تعديل').' الحساب '.$account->label());

        return $account;
    }

    /** @param array<string, mixed> $data */
    public static function transfer(array $data, int $userId): AccountTransfer
    {
        $from = Account::findOrFail($data['from_account_id']);
        $to = Account::findOrFail($data['to_account_id']);
        if ($from->id === $to->id) {
            throw ValidationException::withMessages(['to_account_id' => 'اختر حساباً مختلفاً.']);
        }
        $toAmount = $from->currency === $to->currency ? $data['amount'] : ($data['to_amount'] ?? null);
        if (! $toAmount || $toAmount <= 0) {
            throw ValidationException::withMessages(['to_amount' => "اكتب المبلغ الواصل بعملة {$to->currency}."]);
        }

        return DB::transaction(function () use ($data, $toAmount, $from, $to, $userId) {
            $t = AccountTransfer::create([...$data, 'to_amount' => $toAmount, 'created_by' => $userId]);
            ActivityLogger::log('finance.transfer', null, "تحويل {$t->amount} {$from->currency} من {$from->name} إلى {$to->name}", ['transfer_id' => $t->id]);

            return $t;
        });
    }

    public static function reverseTransfer(AccountTransfer $t, int $userId): void
    {
        if ($t->reversed_at) {
            throw ValidationException::withMessages(['transfer' => 'التحويل معكوس مسبقاً.']);
        }
        $t->forceFill(['reversed_at' => now(), 'reversed_by' => $userId])->save();
        ActivityLogger::log('finance.transfer_reversed', null, "عكس تحويل {$t->amount} من {$t->from?->name} إلى {$t->to?->name}", ['transfer_id' => $t->id]);
    }
}
