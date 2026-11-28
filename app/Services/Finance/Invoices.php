<?php

namespace App\Services\Finance;

use App\Models\Contract;
use App\Models\ContractSchedule;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Project;
use App\Models\Quote;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Setting;
use App\Models\TimeEntry;
use App\Models\User;
use App\Notifications\TaskNotification;
use App\Services\ActivityLogger;
use App\Services\ExchangeRates;
use App\Services\NumberSequencer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only place that changes an invoice's lines or status.
 *   draft --issue--> issued --cancel--> cancelled
 * Issuing takes the INV number, freezes the exchange rate, each line's tax rate and the total.
 * Issued invoices are never edited: cancel (with a reason) and issue a new one.
 * A line can bill a contract instalment (the instalment then points at the invoice) or approved billable
 * time of a project (the time entries then point at the line), so nothing is billed twice.
 */
class Invoices
{
    public static function paymentDays(): int
    {
        $v = Setting::get('invoices.payment_days');

        return $v === null || $v === '' ? (int) config('finance.payment_days', 14) : (int) $v;
    }

    public static function defaultTerms(): ?string
    {
        return Setting::get('invoices.default_terms') ?: null;
    }

    public static function defaultPaymentDetails(): ?string
    {
        return Setting::get('invoices.payment_details') ?: null;
    }

    /**
     * Save a draft and replace its lines. Lines keep `contract_schedule_id` and `time_ids` (billable time).
     *
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $items
     */
    public static function saveDraft(Invoice $invoice, array $data, array $items): Invoice
    {
        if ($invoice->exists && ! $invoice->isDraft()) {
            throw ValidationException::withMessages(['status' => 'لا يمكن تعديل فاتورة صادرة. ألغِها وأصدر فاتورة جديدة.']);
        }

        return DB::transaction(function () use ($invoice, $data, $items) {
            $invoice->fill($data);
            $invoice->status = 'draft';
            $invoice->save();

            $rates = ServiceCategory::pluck('tax_rate', 'id');
            $services = Service::withTrashed()->whereIn('id', array_filter(array_column($items, 'service_id')))->get()->keyBy('id');
            $schedules = self::claimableSchedules($invoice, array_filter(array_column($items, 'contract_schedule_id')));
            $timeIds = collect($items)->pluck('time_ids')->flatten()->filter()->map(fn ($v) => (int) $v)->unique()->values();
            $time = self::claimableTime($invoice, $timeIds->all());

            // Release what the old lines held, then rebuild.
            $oldIds = $invoice->items()->pluck('id');
            TimeEntry::whereIn('invoice_item_id', $oldIds)->update(['invoice_item_id' => null]);
            ContractSchedule::where('invoice_id', $invoice->id)->update(['invoice_id' => null]);
            $invoice->items()->delete();

            foreach (array_values($items) as $i => $row) {
                $service = isset($row['service_id']) ? $services->get($row['service_id']) : null;
                $categoryId = $row['service_category_id'] ?? $service?->service_category_id;
                $schedule = isset($row['contract_schedule_id']) ? $schedules->get((int) $row['contract_schedule_id']) : null;
                $line = $invoice->items()->create([
                    'service_id' => $service?->id,
                    'service_category_id' => $categoryId,
                    'contract_schedule_id' => $schedule?->id,
                    'name' => $row['name'],
                    'details' => $row['details'] ?? null,
                    'quantity' => $row['quantity'],
                    'unit' => $row['unit'] ?? null,
                    'unit_price' => $row['unit_price'],
                    'discount_percent' => $row['discount_percent'] ?? 0,
                    'tax_rate' => $categoryId ? (float) ($rates[$categoryId] ?? 0) : 0,
                    'sort' => $i,
                ]);
                if ($schedule) {
                    $schedule->forceFill(['invoice_id' => $invoice->id])->save();
                }
                $ids = collect($row['time_ids'] ?? [])->map(fn ($v) => (int) $v)->intersect($time->keys());
                if ($ids->isNotEmpty()) {
                    TimeEntry::whereIn('id', $ids)->update(['invoice_item_id' => $line->id]);
                }
            }

            return $invoice->unsetRelation('items');
        });
    }

    public static function issue(Invoice $invoice, User $by): Invoice
    {
        if (! $invoice->isDraft()) {
            throw ValidationException::withMessages(['status' => 'الفاتورة صادرة مسبقاً.']);
        }
        if (! $invoice->items()->exists()) {
            throw ValidationException::withMessages(['items' => 'أضف بنداً واحداً على الأقل قبل الإصدار.']);
        }
        $rate = ExchangeRates::rateFor($invoice->currency);
        if ($rate === null) {
            throw ValidationException::withMessages(['currency' => "لا يوجد سعر صرف مُدخل لعملة {$invoice->currency}. أدخله من الإعدادات أولاً."]);
        }

        return DB::transaction(function () use ($invoice, $rate, $by) {
            $rates = ServiceCategory::pluck('tax_rate', 'id');
            foreach ($invoice->items as $item) {
                if ($item->service_category_id) {
                    $item->update(['tax_rate' => (float) ($rates[$item->service_category_id] ?? 0)]);
                }
            }
            $invoice->unsetRelation('items');
            $total = $invoice->totals()['total'];
            if ($total <= 0) {
                throw ValidationException::withMessages(['items' => 'إجمالي الفاتورة يجب أن يكون أكبر من صفر.']);
            }

            $invoice->number = NumberSequencer::next('invoice');
            $invoice->status = 'issued';
            $invoice->issue_date ??= today();
            if ($invoice->issue_date->gt(today())) {
                $invoice->issue_date = today();
            }
            if (! $invoice->due_date || $invoice->due_date->lt($invoice->issue_date)) {
                $invoice->due_date = $invoice->issue_date->copy()->addDays(self::paymentDays());
            }
            $invoice->fx_rate = $rate['rate'];
            $invoice->total = $total;
            $invoice->issued_at = now();
            $invoice->save();

            ActivityLogger::log('finance.invoice_issued', $invoice, "إصدار الفاتورة {$invoice->number}", [
                'total' => $total, 'currency' => $invoice->currency, 'fx_rate' => $invoice->fx_rate,
            ]);

            return $invoice;
        });
    }

    /** Cancel an issued invoice (its number stays used). Releases instalments and time so they can be billed again. */
    public static function cancel(Invoice $invoice, string $reason): Invoice
    {
        if (! $invoice->isIssued()) {
            throw ValidationException::withMessages(['status' => 'يمكن إلغاء الفواتير الصادرة فقط.']);
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'اكتب سبب الإلغاء.']);
        }
        if ($invoice->paidAmount() > 0) {
            throw ValidationException::withMessages(['status' => 'على الفاتورة دفعات مسجّلة. اعكس الدفعات أو ردّها أولاً.']);
        }

        return DB::transaction(function () use ($invoice, $reason) {
            $invoice->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => $reason])->save();
            self::release($invoice);
            ActivityLogger::log('finance.invoice_cancelled', $invoice, "إلغاء الفاتورة {$invoice->number}", ['reason' => $reason]);

            return $invoice;
        });
    }

    /** Archive a draft (soft delete) and free what it held. */
    public static function deleteDraft(Invoice $invoice): void
    {
        if (! $invoice->isDraft()) {
            throw ValidationException::withMessages(['status' => 'لا يمكن حذف فاتورة صادرة؛ ألغِها بدلاً من ذلك.']);
        }
        DB::transaction(function () use ($invoice) {
            self::release($invoice);
            $invoice->delete();
            ActivityLogger::log('finance.invoice_deleted', $invoice, "حذف مسودة الفاتورة: {$invoice->title}");
        });
    }

    /** Copy an invoice into a new draft (lines only; instalment and time links are not copied). */
    public static function duplicate(Invoice $invoice, int $userId): Invoice
    {
        return DB::transaction(function () use ($invoice, $userId) {
            $copy = new Invoice([
                ...$invoice->only('client_id', 'contact_id', 'project_id', 'contract_id', 'title', 'currency', 'intro', 'terms', 'notes', 'payment_details'),
                'owner_id' => $userId,
                'created_by' => $userId,
                'source_invoice_id' => $invoice->id,
            ]);
            $items = $invoice->items->map(fn ($it) => $it->only('service_id', 'service_category_id', 'name', 'details', 'quantity', 'unit', 'unit_price', 'discount_percent'))->all();
            self::saveDraft($copy, [], $items);
            ActivityLogger::log('finance.invoice_created', $copy, 'نسخة جديدة من الفاتورة '.($invoice->number ?? "#{$invoice->id}"));

            return $copy;
        });
    }

    // ── Pre-filled drafts ──────────────────────────────────────────────

    /** Form values for a new invoice. @return array<string, mixed> */
    public static function blank(?int $clientId = null, string $currency = 'USD'): array
    {
        return [
            'client_id' => $clientId, 'contact_id' => null, 'project_id' => null, 'contract_id' => null, 'quote_id' => null,
            'title' => '', 'currency' => $currency, 'issue_date' => today()->toDateString(), 'due_date' => null,
            'intro' => '', 'terms' => self::defaultTerms() ?? '', 'notes' => '', 'payment_details' => self::defaultPaymentDetails() ?? '',
            'owner_id' => null, 'items' => [],
        ];
    }

    /** From an accepted quote: its lines and links. @return array<string, mixed> */
    public static function fromQuote(Quote $quote): array
    {
        if ($quote->status !== 'accepted') {
            throw ValidationException::withMessages(['quote_id' => 'تُنشأ الفاتورة من عرض مقبول فقط.']);
        }

        return [
            ...self::blank($quote->client_id, $quote->currency),
            'contact_id' => $quote->contact_id, 'quote_id' => $quote->id, 'project_id' => $quote->project_id,
            'title' => $quote->title,
            'items' => $quote->items->map(fn ($it) => [
                ...$it->only('service_id', 'service_category_id', 'name', 'details', 'unit'),
                'quantity' => (float) $it->quantity, 'unit_price' => (float) $it->unit_price, 'discount_percent' => (float) $it->discount_percent,
            ])->all(),
        ];
    }

    /**
     * From pending payment instalments of one contract (one line each).
     *
     * @param  list<int>  $scheduleIds
     * @return array<string, mixed>
     */
    public static function fromSchedules(Contract $contract, array $scheduleIds): array
    {
        $rows = $contract->schedules()->whereIn('id', $scheduleIds)->get();
        if ($rows->isEmpty() || $rows->contains(fn (ContractSchedule $s) => ! self::schedulePending($s))) {
            throw ValidationException::withMessages(['schedule' => 'اختر دفعات معلّقة غير مفوترة من هذا العقد.']);
        }

        return [
            ...self::blank($contract->client_id, $contract->currency),
            'contact_id' => $contract->contact_id, 'contract_id' => $contract->id, 'project_id' => $contract->project_id,
            'title' => $rows->count() === 1 ? "{$contract->title} — {$rows->first()->title}" : $contract->title,
            'due_date' => $rows->count() === 1 ? $rows->first()->due_date?->toDateString() : null,
            'items' => $rows->map(fn (ContractSchedule $s) => self::scheduleLine($contract, $s))->all(),
        ];
    }

    /** @return array<string, mixed> */
    public static function scheduleLine(Contract $contract, ContractSchedule $s): array
    {
        $period = $s->period_start ? $s->period_start->format('Y-m-d').' → '.$s->period_end?->format('Y-m-d') : null;

        return [
            'service_id' => null, 'service_category_id' => null, 'contract_schedule_id' => $s->id,
            'name' => $s->title, 'details' => trim(implode("\n", array_filter(['العقد '.($contract->number ?? '#'.$contract->id).($period ? " · الفترة $period" : ''), $s->details]))),
            'quantity' => 1, 'unit' => null, 'unit_price' => (float) $s->amount, 'discount_percent' => 0,
        ];
    }

    public static function schedulePending(ContractSchedule $s): bool
    {
        return $s->kind === 'payment' && $s->status === 'pending' && $s->invoice_id === null && (float) $s->amount > 0;
    }

    /**
     * Approved, billable, not yet invoiced time of a project, grouped per task.
     * Approved = the person's week (Saturday start) has an approved timesheet.
     *
     * @return Collection<int, array{task_id: ?int, title: string, minutes: int, time_ids: list<int>}>
     */
    public static function billableTime(Project $project, ?Invoice $except = null): Collection
    {
        $entries = TimeEntry::with('task:id,title,client_id,seq')->where('project_id', $project->id)->where('billable', true)
            ->whereNotNull('ended_at')->where('minutes', '>', 0)
            ->where(fn ($q) => $q->whereNull('invoice_item_id')
                ->when($except?->exists, fn ($q) => $q->orWhereIn('invoice_item_id', $except->items()->pluck('id'))))
            ->orderBy('started_at')->get();
        $approved = \App\Models\Timesheet::where('status', 'approved')->whereIn('user_id', $entries->pluck('user_id')->unique())
            ->get(['user_id', 'week_start'])->map(fn ($t) => $t->user_id.'|'.$t->week_start->toDateString())->flip();
        $weekOf = fn (TimeEntry $e) => \App\Services\Tasks\Timesheets::weekStart($e->started_at)->toDateString();

        return $entries->filter(fn (TimeEntry $e) => isset($approved[$e->user_id.'|'.$weekOf($e)]))
            ->groupBy(fn (TimeEntry $e) => $e->task_id ?? 0)
            ->map(fn (Collection $g, $taskId) => [
                'task_id' => $taskId ?: null,
                'title' => $g->first()->task ? $g->first()->task->code().' '.$g->first()->task->title : 'وقت بلا مهمة',
                'minutes' => (int) $g->sum('minutes'),
                'time_ids' => $g->pluck('id')->all(),
            ])->values();
    }

    /**
     * Daily: a draft invoice for every pending retainer instalment due within the lead days.
     * The contract owner is notified to review and issue it.
     */
    public static function draftRetainers(?int $leadDays = null): int
    {
        $leadDays ??= (int) (Setting::get('invoices.retainer_lead_days') ?: config('finance.retainer_lead_days', 7));
        $rows = ContractSchedule::with('contract')->where('kind', 'payment')->where('status', 'pending')->whereNull('invoice_id')
            ->whereNotNull('period_start')->whereDate('due_date', '<=', today()->addDays($leadDays))
            ->whereHas('contract', fn ($q) => $q->where('type', 'retainer')->where('status', 'active'))
            ->orderBy('due_date')->get();
        $n = 0;
        foreach ($rows as $s) {
            $c = $s->contract;
            $form = self::fromSchedules($c, [$s->id]);
            $ownerId = $c->owner_id ?? $c->created_by;
            $invoice = self::saveDraft(new Invoice(['created_by' => $ownerId]), [
                ...collect($form)->except('items')->all(), 'owner_id' => $ownerId,
            ], $form['items']);
            ActivityLogger::log('finance.invoice_created', $invoice, "مسودة فاتورة تلقائية لدفعة العقد {$c->number}: {$s->title}");
            if ($ownerId && ($owner = User::find($ownerId))) {
                $owner->notify(new TaskNotification([
                    'kind' => 'finance.invoice_draft',
                    'title' => 'مسودة فاتورة جاهزة للإصدار',
                    'body' => "{$c->client?->name} — {$s->title}",
                    'url' => "/finance/invoices/{$invoice->id}",
                ]));
            }
            $n++;
        }

        return $n;
    }

    // ── internals ──────────────────────────────────────────────────────

    /** Free the instalments and time entries an invoice holds. */
    private static function release(Invoice $invoice): void
    {
        ContractSchedule::where('invoice_id', $invoice->id)->where('status', 'pending')->update(['invoice_id' => null]);
        TimeEntry::whereIn('invoice_item_id', $invoice->items()->pluck('id'))->update(['invoice_item_id' => null]);
    }

    /** Instalments this draft may bill: pending payments of the invoice's client, free or already on this invoice. */
    private static function claimableSchedules(Invoice $invoice, array $ids): Collection
    {
        if (! $ids) {
            return collect();
        }
        $rows = ContractSchedule::with('contract:id,client_id')->whereIn('id', $ids)->get();
        foreach ($rows as $s) {
            $ok = $s->kind === 'payment' && $s->status === 'pending' && (int) $s->contract?->client_id === (int) $invoice->client_id
                && ($s->invoice_id === null || (int) $s->invoice_id === (int) $invoice->id);
            if (! $ok) {
                throw ValidationException::withMessages(['items' => "الدفعة «{$s->title}» مفوترة مسبقاً أو لا تتبع هذا العميل."]);
            }
        }

        return $rows->keyBy('id');
    }

    /** Time entries this draft may bill: billable, of the client's projects, free or already on this invoice. */
    private static function claimableTime(Invoice $invoice, array $ids): Collection
    {
        if (! $ids) {
            return collect();
        }
        $own = $invoice->items()->pluck('id');

        return TimeEntry::whereIn('id', $ids)->where('billable', true)->where('client_id', $invoice->client_id)
            ->where(fn ($q) => $q->whereNull('invoice_item_id')->orWhereIn('invoice_item_id', $own))
            ->get()->keyBy('id');
    }
}
