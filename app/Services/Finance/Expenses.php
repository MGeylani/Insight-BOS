<?php

namespace App\Services\Finance;

use App\Models\Account;
use App\Models\Advance;
use App\Models\Attachment;
use App\Models\Expense;
use App\Models\Project;
use App\Models\User;
use App\Notifications\TaskNotification;
use App\Services\ActivityLogger;
use App\Services\ExchangeRates;
use App\Services\NumberSequencer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only place that changes expenses.
 *   save()    → pending (new: numbered EXP-…, approvers notified; edits allowed while pending)
 *   approve() → approved (receipt attached, advance has enough left); from now it counts in reports and account balances
 *   reject()  → rejected (reason); withdraw/cancel() → cancelled (approved ones only while nothing settled them)
 */
class Expenses
{
    /** @param array<string, mixed> $data */
    public static function save(Expense $e, array $data, User $actor): Expense
    {
        if ($e->exists && ! $e->isPending()) {
            throw ValidationException::withMessages(['status' => 'يُعدَّل المصروف وهو بانتظار الاعتماد فقط.']);
        }
        $data['user_id'] = ($data['user_id'] ?? null) && self::canEnterForOthers($actor) ? (int) $data['user_id'] : ($e->user_id ?? $actor->id);
        $date = Carbon::parse($data['date']);
        if ($date->isAfter(today())) {
            throw ValidationException::withMessages(['date' => 'لا يُسجَّل مصروف بتاريخ مستقبلي.']);
        }
        if (! in_array($data['paid_by'], Expense::PAID_BY, true)) {
            throw ValidationException::withMessages(['paid_by' => 'طريقة الدفع غير صالحة.']);
        }
        $accountId = $data['account_id'] ?? null;
        $advanceId = $data['advance_id'] ?? null;
        $data['account_id'] = $data['advance_id'] = null;
        if ($data['paid_by'] !== 'credit') {
            $data['due_date'] = null;
        }

        if ($data['paid_by'] === 'account') {
            $a = Account::where('is_active', true)->find($accountId) ?? throw ValidationException::withMessages(['account_id' => 'اختر الحساب الذي دُفع منه.']);
            $data['account_id'] = $a->id;
            $data['currency'] = $a->currency;
        } elseif ($data['paid_by'] === 'advance') {
            $adv = Advance::posted()->where('user_id', $data['user_id'])->find($advanceId)
                ?? throw ValidationException::withMessages(['advance_id' => 'اختر عهدة/سلفة مفتوحة لهذا الموظف.']);
            $data['advance_id'] = $adv->id;
            $data['currency'] = $adv->currency;
        } elseif ($data['paid_by'] === 'credit') {
            if (empty($data['vendor_id'])) {
                throw ValidationException::withMessages(['vendor_id' => 'فاتورة المورد تحتاج اسم المورد.']);
            }
            $data['due_date'] = ($data['due_date'] ?? null) ?: $date->copy()->addDays((int) config('finance.vendor_payment_days', 30))->toDateString();
        }
        $data['client_id'] = ! empty($data['project_id']) ? Project::find($data['project_id'])?->client_id : ($data['client_id'] ?? null);
        $data['fx_rate'] = self::rate($data['currency'], $date);

        $new = ! $e->exists;

        return DB::transaction(function () use ($e, $data, $actor, $new) {
            $e->fill($data + ($new ? ['created_by' => $actor->id] : []));
            if ($new) {
                $e->number = NumberSequencer::next('expense');
                $e->status = 'pending';
            }
            $e->save();
            ActivityLogger::log($new ? 'finance.expense_created' : 'finance.expense_updated', $e, ($new ? 'تقديم' : 'تعديل')." المصروف {$e->number}: {$e->amount} {$e->currency} — {$e->description}");
            if ($new) {
                self::notify(self::approvers($e)->reject(fn (User $u) => $u->id === $actor->id), $e, 'finance.expense_submitted',
                    "مصروف بانتظار الاعتماد: {$e->number}", ($e->user?->name ?? '').' — '.number_format((float) $e->amount, 2)." {$e->currency} · {$e->description}");
            }

            return $e;
        });
    }

    public static function approve(Expense $e, User $by, ?string $note = null): Expense
    {
        self::assertDecider($by, $e);
        if (config('finance.expense_receipt_required', true) && ! self::hasReceipt($e)) {
            throw ValidationException::withMessages(['receipt' => 'أرفق صورة الإيصال أو فاتورة المورد قبل الاعتماد.']);
        }
        if ($e->paid_by === 'advance') {
            $left = $e->advance?->balance() ?? 0;
            if ((float) $e->amount > $left + 0.001) {
                throw ValidationException::withMessages(['advance_id' => "المتبقي في العهدة {$left} {$e->currency} لا يكفي. سجّل الفرق كمصروف من الموظف."]);
            }
        }

        $e = self::decide($e, $by, 'approved', $note, 'اعتماد');
        Budgets::checkExpense($e);

        return $e;
    }

    public static function reject(Expense $e, User $by, string $reason): Expense
    {
        self::assertDecider($by, $e);
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'اكتب سبب الرفض.']);
        }

        return self::decide($e, $by, 'rejected', $reason, 'رفض');
    }

    /** Withdraw a pending expense (its submitter) or cancel an approved one nothing has settled yet (finance). */
    public static function cancel(Expense $e, User $by, string $reason): Expense
    {
        if (! in_array($e->status, ['pending', 'approved'], true)) {
            throw ValidationException::withMessages(['status' => 'المصروف منتهٍ مسبقاً.']);
        }
        if ($e->isApproved() && ($e->reimbursement_id || $e->allocations()->exists())) {
            throw ValidationException::withMessages(['status' => 'سُدّد المصروف (تعويض أو دفعة مورد). اعكس الدفعة أولاً.']);
        }
        if ($e->isApproved() && trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'اكتب سبب الإلغاء.']);
        }
        $e->forceFill(['status' => 'cancelled', 'decision_note' => $reason ?: $e->decision_note])->save();
        ActivityLogger::log('finance.expense_cancelled', $e, "إلغاء المصروف {$e->number}", ['reason' => $reason]);

        return $e;
    }

    private static function decide(Expense $e, User $by, string $status, ?string $note, string $verb): Expense
    {
        if (! $e->isPending()) {
            throw ValidationException::withMessages(['status' => 'المصروف ليس بانتظار الاعتماد.']);
        }
        $e->forceFill(['status' => $status, 'decided_by' => $by->id, 'decided_at' => now(), 'decision_note' => $note])->save();
        ActivityLogger::log("finance.expense_$status", $e, "$verb المصروف {$e->number}", $note ? ['note' => $note] : []);
        $to = User::whereIn('id', array_unique(array_filter([$e->user_id, $e->created_by])))->where('id', '!=', $by->id)->get();
        self::notify($to, $e, 'finance.expense_decided', ($status === 'approved' ? 'اعتُمد' : 'رُفض')." المصروف {$e->number}", $e->description.($note ? " — $note" : ''));

        return $e;
    }

    public static function hasReceipt(Expense $e): bool
    {
        return Attachment::where('attachable_type', 'expense')->where('attachable_id', $e->id)->where('status', '!=', 'failed')->exists();
    }

    /** Approvers: finance.approve with scope all, plus the project manager when their scope is "assigned". Never the person who spent it. */
    public static function canDecide(User $u, Expense $e): bool
    {
        if (! $e->isPending() || (! $u->is_super_admin && in_array($u->id, [(int) $e->user_id, (int) $e->created_by], true))) {
            return false;
        }

        return match ($u->permissionScope('finance.approve')) {
            'all' => true,
            'assigned' => $e->project_id && (int) $e->project?->manager_id === $u->id,
            default => false,
        };
    }

    public static function canEdit(User $u, Expense $e): bool
    {
        return $e->isPending() && (in_array($u->id, [(int) $e->user_id, (int) $e->created_by], true) || $u->permissionScope('finance.edit') === 'all');
    }

    public static function canAttach(User $u, Expense $e): bool
    {
        return self::canEdit($u, $e) || self::canDecide($u, $e) || ($e->isVisibleTo($u) && $u->permissionScope('finance.edit') === 'all');
    }

    public static function canCancel(User $u, Expense $e): bool
    {
        return match ($e->status) {
            'pending' => self::canEdit($u, $e),
            'approved' => $u->permissionScope('finance.delete') === 'all' && ! $e->reimbursement_id,
            default => false,
        };
    }

    /** Finance can record an expense on behalf of someone else. */
    public static function canEnterForOthers(User $u): bool
    {
        return $u->permissionScope('finance.edit') === 'all' || $u->permissionScope('finance.create') === 'all';
    }

    /** @return Collection<int, User> */
    public static function approvers(Expense $e): Collection
    {
        $all = User::where('is_active', true)->get()->filter(fn (User $u) => $u->permissionScope('finance.approve') === 'all');
        if ($e->project_id && ($pm = $e->project?->manager) && $pm->is_active && $pm->permissionScope('finance.approve') === 'assigned') {
            $all->push($pm);
        }

        return $all->reject(fn (User $u) => in_array($u->id, [(int) $e->user_id, (int) $e->created_by], true))->unique('id')->values();
    }

    /** @param iterable<User> $users */
    private static function notify(iterable $users, Expense $e, string $kind, string $title, string $body): void
    {
        foreach ($users as $u) {
            $u->notify(new TaskNotification(['kind' => $kind, 'title' => $title, 'body' => $body, 'url' => "/finance/expenses/{$e->id}"]));
        }
    }

    private static function assertDecider(User $by, Expense $e): void
    {
        if (! self::canDecide($by, $e)) {
            abort(403, $e->isPending() ? 'لا يمكنك اعتماد هذا المصروف (ولا يعتمد أحد مصروفه بنفسه).' : 'المصروف ليس بانتظار الاعتماد.');
        }
    }

    public static function rate(string $currency, Carbon|string $date): float
    {
        $r = ExchangeRates::rateFor($currency, Carbon::parse($date));
        if ($r === null) {
            throw ValidationException::withMessages(['currency' => "لا يوجد سعر صرف مُدخل لعملة {$currency}."]);
        }

        return $r['rate'];
    }
}
