<?php

namespace App\Services\Sales;

use App\Models\Opportunity;
use App\Models\Quote;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Setting;
use App\Services\ActivityLogger;
use App\Services\Crm\OpportunityStages;
use App\Services\ExchangeRates;
use App\Services\NumberSequencer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only place that changes a quote's lines or status.
 *   draft --issue--> sent --accept--> accepted   (opportunity -> won)
 *                         --reject--> rejected
 *   sent / rejected --cancel--> cancelled
 * Issuing takes the number, freezes the exchange rate and each line's tax rate, and moves the
 * linked opportunity to "sent". Issued quotes are never edited: duplicate them into a new draft.
 */
class Quotes
{
    public const DEFAULT_VALIDITY_DAYS = 30;

    public static function validityDays(): int
    {
        return (int) (Setting::get('quotes.validity_days') ?: self::DEFAULT_VALIDITY_DAYS);
    }

    public static function defaultTerms(): ?string
    {
        return Setting::get('quotes.default_terms') ?: null;
    }

    /**
     * Save a draft and replace its lines. Each line's tax rate follows its category's current rate.
     *
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $items
     */
    public static function saveDraft(Quote $quote, array $data, array $items): Quote
    {
        if ($quote->exists && ! $quote->isDraft()) {
            throw ValidationException::withMessages(['status' => 'لا يمكن تعديل عرض صادر. أنشئ نسخة جديدة منه.']);
        }

        return DB::transaction(function () use ($quote, $data, $items) {
            $quote->fill($data);
            $quote->status = 'draft';
            $quote->save();

            $rates = ServiceCategory::pluck('tax_rate', 'id');
            $services = Service::withTrashed()->whereIn('id', array_filter(array_column($items, 'service_id')))->get()->keyBy('id');

            $quote->items()->delete();
            foreach (array_values($items) as $i => $row) {
                $service = isset($row['service_id']) ? $services->get($row['service_id']) : null;
                $categoryId = $row['service_category_id'] ?? $service?->service_category_id;
                $quote->items()->create([
                    'service_id' => $service?->id,
                    'service_category_id' => $categoryId,
                    'name' => $row['name'],
                    'details' => $row['details'] ?? null,
                    'quantity' => $row['quantity'],
                    'unit' => $row['unit'] ?? null,
                    'unit_price' => $row['unit_price'],
                    'discount_percent' => $row['discount_percent'] ?? 0,
                    'tax_rate' => $categoryId ? (float) ($rates[$categoryId] ?? 0) : 0,
                    'sort' => $i,
                ]);
            }

            return $quote->unsetRelation('items');
        });
    }

    public static function issue(Quote $quote): Quote
    {
        if (! $quote->isDraft()) {
            throw ValidationException::withMessages(['status' => 'العرض صادر مسبقاً.']);
        }
        if (! $quote->items()->exists()) {
            throw ValidationException::withMessages(['items' => 'أضف بنداً واحداً على الأقل قبل الإصدار.']);
        }
        $rate = ExchangeRates::rateFor($quote->currency);
        if ($rate === null) {
            throw ValidationException::withMessages(['currency' => "لا يوجد سعر صرف مُدخل لعملة {$quote->currency}. أدخله من الإعدادات أولاً."]);
        }

        return DB::transaction(function () use ($quote, $rate) {
            // Freeze each line's tax rate at the moment of issue.
            $rates = ServiceCategory::pluck('tax_rate', 'id');
            foreach ($quote->items as $item) {
                if ($item->service_category_id) {
                    $item->update(['tax_rate' => (float) ($rates[$item->service_category_id] ?? 0)]);
                }
            }

            $quote->number = NumberSequencer::next('quote');
            $quote->status = 'sent';
            $quote->issue_date = today();
            if (! $quote->valid_until || $quote->valid_until->lt(today())) {
                $quote->valid_until = today()->addDays(self::validityDays());
            }
            $quote->fx_rate = $rate['rate'];
            $quote->sent_at = now();
            $quote->save();
            $quote->unsetRelation('items');

            $total = $quote->totals()['total'];
            ActivityLogger::log('crm.quote_issued', $quote, "إصدار عرض السعر {$quote->number}", [
                'total' => $total, 'currency' => $quote->currency, 'fx_rate' => $quote->fx_rate,
            ]);

            // The latest issued quote is the deal value; move the opportunity forward if it is earlier in the funnel.
            $opp = $quote->opportunity;
            if ($opp && ! $opp->trashed() && ! $opp->isClosed()) {
                $opp->update(['amount' => $total, 'currency' => $quote->currency]);
                if (in_array($opp->stage, ['discovery', 'proposal'], true)) {
                    OpportunityStages::move($opp, 'sent');
                }
            }

            return $quote;
        });
    }

    public static function accept(Quote $quote): Quote
    {
        self::requireStatus($quote, ['sent'], 'يمكن قبول العروض المُرسلة فقط.');

        return DB::transaction(function () use ($quote) {
            $quote->forceFill(['status' => 'accepted', 'accepted_at' => now()])->save();
            ActivityLogger::log('crm.quote_accepted', $quote, "قبول العميل لعرض السعر {$quote->number}");

            $opp = $quote->opportunity;
            if ($opp && ! $opp->trashed() && $opp->stage !== 'won') {
                OpportunityStages::move($opp, 'won');
            }

            return $quote;
        });
    }

    public static function reject(Quote $quote, string $reason): Quote
    {
        self::requireStatus($quote, ['sent'], 'يمكن تسجيل رفض العروض المُرسلة فقط.');
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'اكتب سبب الرفض.']);
        }
        $quote->forceFill(['status' => 'rejected', 'rejected_at' => now(), 'rejection_reason' => $reason])->save();
        ActivityLogger::log('crm.quote_rejected', $quote, "رفض العميل لعرض السعر {$quote->number}", ['reason' => $reason]);

        return $quote;
    }

    public static function cancel(Quote $quote): Quote
    {
        self::requireStatus($quote, ['sent', 'rejected'], 'لا يمكن إلغاء هذا العرض.');
        $quote->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();
        ActivityLogger::log('crm.quote_cancelled', $quote, "إلغاء عرض السعر {$quote->number}");

        return $quote;
    }

    /** Copy any quote (lines included) into a new draft owned by $userId. */
    public static function duplicate(Quote $quote, int $userId): Quote
    {
        return DB::transaction(function () use ($quote, $userId) {
            $copy = new Quote([
                ...$quote->only('client_id', 'opportunity_id', 'contact_id', 'title', 'currency', 'intro', 'terms', 'notes'),
                'owner_id' => $userId,
                'created_by' => $userId,
                'source_quote_id' => $quote->id,
                'valid_until' => today()->addDays(self::validityDays()),
            ]);
            $items = $quote->items->map(fn ($it) => $it->only('service_id', 'service_category_id', 'name', 'details', 'quantity', 'unit', 'unit_price', 'discount_percent'))->all();
            self::saveDraft($copy, [], $items);
            ActivityLogger::log('crm.quote_created', $copy, 'نسخة جديدة من عرض السعر '.($quote->number ?? "#{$quote->id}"));

            return $copy;
        });
    }

    /** @param list<string> $allowed */
    private static function requireStatus(Quote $quote, array $allowed, string $message): void
    {
        if (! in_array($quote->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => $message]);
        }
    }

    /** Opportunities a new quote may attach to: open ones of the client. */
    public static function attachableOpportunity(?int $opportunityId, int $clientId): ?Opportunity
    {
        if (! $opportunityId) {
            return null;
        }
        $opp = Opportunity::find($opportunityId);
        if (! $opp || (int) $opp->client_id !== $clientId) {
            throw ValidationException::withMessages(['opportunity_id' => 'الفرصة لا تتبع هذا العميل.']);
        }

        return $opp;
    }
}
