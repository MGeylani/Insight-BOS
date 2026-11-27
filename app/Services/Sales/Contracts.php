<?php

namespace App\Services\Sales;

use App\Models\Contract;
use App\Models\ContractSchedule;
use App\Models\Quote;
use App\Models\Setting;
use App\Services\ActivityLogger;
use App\Services\Crm\OpportunityStages;
use App\Services\ExchangeRates;
use App\Services\NumberSequencer;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only place that changes a contract's schedule or status.
 *   draft --issue--> sent --sign--> active --complete--> completed
 *                     |              |----terminate--> terminated  (later pending rows are cancelled)
 *                     |              '----renew------> active      (retainer: one more term)
 *                     '--cancel--> cancelled
 * Issuing takes the number (CTR-…) and freezes the exchange rate. Issued contracts are never edited:
 * duplicate them into a new draft. Signing wins the linked opportunity.
 *
 * Project contracts carry an agreed value and a hand-made schedule of payments and milestones; at issue
 * the payments must add up to the value. Retainer payments are generated: one per month of the term,
 * due at the start (advance) or end (arrears) of each period.
 */
class Contracts
{
    public static function defaultTerms(string $type): ?string
    {
        return Setting::get("contracts.terms_$type") ?: null;
    }

    /**
     * Save a draft and replace its schedule.
     *
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $rows
     */
    public static function saveDraft(Contract $contract, array $data, array $rows): Contract
    {
        if ($contract->exists && ! $contract->isDraft()) {
            throw ValidationException::withMessages(['status' => 'لا يمكن تعديل عقد صادر. أنشئ نسخة جديدة منه.']);
        }

        return DB::transaction(function () use ($contract, $data, $rows) {
            $contract->fill($data);
            $contract->status = 'draft';

            if ($contract->isRetainer()) {
                $contract->value = null;
                $contract->billing_timing ??= 'advance';
                $contract->end_date = ($contract->start_date && $contract->term_months)
                    ? self::periodStart($contract->start_date, $contract->term_months)->subDay()
                    : null;
            } else {
                $contract->monthly_fee = null;
                $contract->term_months = null;
                $contract->billing_timing = null;
                $contract->auto_renew = false;
            }
            $contract->save();

            $contract->schedules()->delete();
            $sort = 0;
            if ($contract->isRetainer()) {
                self::addRetainerPayments($contract, 0, (int) $contract->term_months, $sort);
            }
            foreach (array_values($rows) as $row) {
                $kind = $row['kind'] ?? 'payment';
                if ($contract->isRetainer() && $kind === 'payment') {
                    continue; // retainer payments are generated, never typed in
                }
                $contract->schedules()->create([
                    'kind' => $kind,
                    'title' => $row['title'],
                    'details' => $row['details'] ?? null,
                    'due_date' => $row['due_date'] ?? null,
                    'amount' => $kind === 'payment' ? ($row['amount'] ?? 0) : null,
                    'status' => 'pending',
                    'sort' => $sort++,
                ]);
            }

            return $contract->unsetRelation('schedules');
        });
    }

    public static function issue(Contract $contract): Contract
    {
        if (! $contract->isDraft()) {
            throw ValidationException::withMessages(['status' => 'العقد صادر مسبقاً.']);
        }
        $errors = [];
        if (! $contract->start_date) {
            $errors['start_date'] = 'حدّد تاريخ بدء العقد قبل الإصدار.';
        }
        if ($contract->isRetainer()) {
            if ((float) $contract->monthly_fee <= 0) {
                $errors['monthly_fee'] = 'حدّد الرسوم الشهرية قبل الإصدار.';
            }
            if (! $contract->term_months) {
                $errors['term_months'] = 'حدّد مدة العقد بالأشهر.';
            }
        } else {
            $payments = $contract->schedules()->where('kind', 'payment')->get();
            if ((float) $contract->value <= 0) {
                $errors['value'] = 'حدّد قيمة العقد قبل الإصدار.';
            } elseif ($payments->isEmpty()) {
                $errors['schedule'] = 'أضف دفعة واحدة على الأقل إلى جدول الدفعات.';
            } elseif (abs($contract->scheduledTotal() - (float) $contract->value) >= 0.005) {
                $errors['schedule'] = sprintf('مجموع الدفعات (%s) لا يساوي قيمة العقد (%s).',
                    number_format($contract->scheduledTotal(), 2), number_format((float) $contract->value, 2));
            }
        }
        $rate = ExchangeRates::rateFor($contract->currency);
        if ($rate === null) {
            $errors['currency'] = "لا يوجد سعر صرف مُدخل لعملة {$contract->currency}. أدخله من الإعدادات أولاً.";
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($contract, $rate) {
            $contract->number = NumberSequencer::next('contract');
            $contract->status = 'sent';
            $contract->issue_date = today();
            $contract->fx_rate = $rate['rate'];
            $contract->sent_at = now();
            $contract->save();

            ActivityLogger::log('crm.contract_issued', $contract, "إصدار العقد {$contract->number} للتوقيع", [
                'type' => $contract->type, 'total' => $contract->totalValue(), 'currency' => $contract->currency, 'fx_rate' => $contract->fx_rate,
            ]);

            return $contract;
        });
    }

    /** The client signed: the contract starts to run and its opportunity is won. */
    public static function sign(Contract $contract, CarbonInterface $signedOn, ?string $signatory): Contract
    {
        self::requireStatus($contract, ['sent'], 'يمكن تسجيل توقيع العقود المُرسلة فقط.');

        return DB::transaction(function () use ($contract, $signedOn, $signatory) {
            $contract->forceFill(['status' => 'active', 'signed_on' => $signedOn, 'client_signatory' => $signatory ?: null])->save();
            ActivityLogger::log('crm.contract_signed', $contract, "توقيع العقد {$contract->number}", [
                'signed_on' => $signedOn->toDateString(), 'signatory' => $signatory,
            ]);

            $opp = $contract->opportunity;
            if ($opp && ! $opp->trashed() && ! $opp->isClosed()) {
                OpportunityStages::move($opp, 'won');
            }

            return $contract;
        });
    }

    public static function complete(Contract $contract): Contract
    {
        self::requireStatus($contract, ['active'], 'يمكن إنهاء العقود السارية فقط.');
        $contract->forceFill(['status' => 'completed', 'completed_at' => now()])->save();
        ActivityLogger::log('crm.contract_completed', $contract, "إنهاء العقد {$contract->number} بعد اكتماله");

        return $contract;
    }

    /** Early termination: pending rows due after the termination date are cancelled. */
    public static function terminate(Contract $contract, CarbonInterface $on, string $reason): Contract
    {
        self::requireStatus($contract, ['active'], 'يمكن فسخ العقود السارية فقط.');
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'اكتب سبب الفسخ.']);
        }

        return DB::transaction(function () use ($contract, $on, $reason) {
            $cancelled = $contract->schedules()->where('status', 'pending')
                ->where(fn ($q) => $q->whereDate('due_date', '>', $on)->orWhereNull('due_date'))
                ->update(['status' => 'cancelled']);
            $contract->forceFill(['status' => 'terminated', 'terminated_on' => $on, 'termination_reason' => $reason])->save();
            ActivityLogger::log('crm.contract_terminated', $contract, "فسخ العقد {$contract->number}", [
                'on' => $on->toDateString(), 'reason' => $reason, 'cancelled_rows' => $cancelled,
            ]);

            return $contract->unsetRelation('schedules');
        });
    }

    public static function cancel(Contract $contract): Contract
    {
        self::requireStatus($contract, ['sent'], 'يمكن إلغاء العقود غير الموقّعة فقط. افسخ العقد الساري بدلاً من ذلك.');
        $contract->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();
        ActivityLogger::log('crm.contract_cancelled', $contract, "إلغاء العقد {$contract->number}");

        return $contract;
    }

    /** Retainer: add one more term after the current end date. */
    public static function renew(Contract $contract): Contract
    {
        self::requireStatus($contract, ['active'], 'يمكن تجديد العقود السارية فقط.');
        if (! $contract->isRetainer() || ! $contract->term_months || ! $contract->start_date) {
            throw ValidationException::withMessages(['status' => 'التجديد متاح للعقود الشهرية فقط.']);
        }

        return DB::transaction(function () use ($contract) {
            $done = self::monthsCovered($contract);
            $sort = (int) $contract->schedules()->max('sort') + 1;
            self::addRetainerPayments($contract, $done, $contract->term_months, $sort);
            $contract->end_date = self::periodStart($contract->start_date, $done + $contract->term_months)->subDay();
            $contract->save();
            ActivityLogger::log('crm.contract_renewed', $contract, "تجديد العقد {$contract->number} حتى {$contract->end_date->toDateString()}", [
                'months' => $contract->term_months, 'end_date' => $contract->end_date->toDateString(),
            ]);

            return $contract->unsetRelation('schedules');
        });
    }

    /** Copy any contract (schedule included) into a new draft owned by $userId. */
    public static function duplicate(Contract $contract, int $userId): Contract
    {
        return DB::transaction(function () use ($contract, $userId) {
            $copy = new Contract([
                ...$contract->only('type', 'client_id', 'contact_id', 'opportunity_id', 'quote_id', 'title', 'currency',
                    'value', 'monthly_fee', 'term_months', 'billing_timing', 'auto_renew', 'notice_days', 'start_date', 'scope', 'terms', 'notes'),
                'owner_id' => $userId,
                'created_by' => $userId,
                'source_contract_id' => $contract->id,
            ]);
            // Keep the original term length; renewals of the source are not copied.
            $rows = $contract->schedules->filter(fn (ContractSchedule $s) => ! $contract->isRetainer() || $s->kind === 'milestone')
                ->map(fn (ContractSchedule $s) => [
                    ...$s->only('kind', 'title', 'details'),
                    'due_date' => $s->due_date?->toDateString(),
                    'amount' => $s->amount,
                ])->values()->all();
            self::saveDraft($copy, [], $rows);
            ActivityLogger::log('crm.contract_created', $copy, 'نسخة جديدة من العقد '.($contract->number ?? "#{$contract->id}"));

            return $copy;
        });
    }

    /** Mark a delivery milestone done, or reopen it. */
    public static function setMilestone(ContractSchedule $row, bool $done, int $userId): ContractSchedule
    {
        $contract = $row->contract;
        if ($row->kind !== 'milestone') {
            throw ValidationException::withMessages(['row' => 'الدفعات تُسوّى بالفواتير، لا يدوياً.']);
        }
        if ($contract->status !== 'active' || $row->status === 'cancelled') {
            throw ValidationException::withMessages(['row' => 'يمكن تحديث مراحل العقود السارية فقط.']);
        }
        $row->forceFill($done
            ? ['status' => 'done', 'done_at' => now(), 'done_by' => $userId]
            : ['status' => 'pending', 'done_at' => null, 'done_by' => null])->save();
        ActivityLogger::log('crm.contract_milestone', $contract, ($done ? 'إنجاز المرحلة: ' : 'إعادة فتح المرحلة: ').$row->title);

        return $row;
    }

    /** Prefill for a new contract from a quote. @return array<string, mixed> */
    public static function fromQuote(Quote $quote): array
    {
        return [
            'type' => 'project',
            'client_id' => $quote->client_id,
            'contact_id' => $quote->contact_id,
            'opportunity_id' => $quote->opportunity_id,
            'quote_id' => $quote->id,
            'title' => $quote->title,
            'currency' => $quote->currency,
            'value' => $quote->totals()['total'],
            'scope' => $quote->items->map(fn ($it) => '• '.$it->name.($it->details ? ': '.str_replace("\n", ' ', $it->details) : ''))->implode("\n"),
        ];
    }

    /** @param list<string> $allowed */
    private static function requireStatus(Contract $contract, array $allowed, string $message): void
    {
        if (! in_array($contract->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => $message]);
        }
    }

    /** Start of the n-th monthly period (0-based). Months never overflow (31 Jan + 1 month = 28/29 Feb). */
    private static function periodStart(CarbonInterface $start, int $n): Carbon
    {
        return Carbon::parse($start)->startOfDay()->addMonthsNoOverflow($n);
    }

    private static function monthsCovered(Contract $contract): int
    {
        return $contract->schedules()->where('kind', 'payment')->whereNotNull('period_start')->count();
    }

    private static function addRetainerPayments(Contract $contract, int $from, int $count, int &$sort): void
    {
        if (! $contract->start_date || $count < 1) {
            return;
        }
        for ($i = $from; $i < $from + $count; $i++) {
            $ps = self::periodStart($contract->start_date, $i);
            $pe = self::periodStart($contract->start_date, $i + 1)->subDay();
            $contract->schedules()->create([
                'kind' => 'payment',
                'title' => 'الدفعة الشهرية '.($i + 1),
                'due_date' => $contract->billing_timing === 'arrears' ? $pe : $ps,
                'amount' => $contract->monthly_fee,
                'period_start' => $ps,
                'period_end' => $pe,
                'status' => 'pending',
                'sort' => $sort++,
            ]);
        }
    }
}
