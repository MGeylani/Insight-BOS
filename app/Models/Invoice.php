<?php

namespace App\Models;

use App\Models\Concerns\VisibleByScope;
use App\Services\ExchangeRates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Stored statuses: draft | issued | cancelled. Shown statuses add, for issued invoices:
 * paid (nothing left), partial (something paid), overdue (past due with a balance). Payments arrive in step 2;
 * until then paidAmount() is 0.
 */
class Invoice extends Model
{
    use HasFactory, SoftDeletes, VisibleByScope;

    public const STATUSES = ['draft', 'issued', 'cancelled'];

    protected $fillable = [
        'client_id', 'contact_id', 'project_id', 'contract_id', 'quote_id', 'title', 'issue_date', 'due_date', 'currency',
        'intro', 'terms', 'notes', 'payment_details', 'owner_id', 'created_by', 'source_invoice_id',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date', 'due_date' => 'date', 'fx_rate' => 'decimal:6', 'total' => 'decimal:2',
            'issued_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isIssued(): bool
    {
        return $this->status === 'issued';
    }

    /** Amount paid so far (payments: phase 4, step 2). Uses a preloaded `paid_sum` when present. */
    public function paidAmount(): float
    {
        if (array_key_exists('paid_sum', $this->attributes)) {
            return round((float) $this->attributes['paid_sum'], 2);
        }

        return method_exists($this, 'allocations') ? round((float) $this->allocations()->sum('amount'), 2) : 0.0;
    }

    public function balance(): float
    {
        return $this->isIssued() ? round($this->grandTotal() - $this->paidAmount(), 2) : 0.0;
    }

    public function isOverdue(): bool
    {
        return $this->isIssued() && $this->due_date && $this->due_date->lt(today()) && $this->balance() > 0;
    }

    public function displayStatus(): string
    {
        if (! $this->isIssued()) {
            return $this->status;
        }
        $paid = $this->paidAmount();
        $balance = $this->balance();

        return match (true) {
            $balance <= 0 => 'paid',
            $this->isOverdue() => 'overdue',
            $paid > 0 => 'partial',
            default => 'issued',
        };
    }

    /** @return array{subtotal: float, discount: float, net: float, tax: float, total: float} */
    public function totals(): array
    {
        $items = $this->relationLoaded('items') ? $this->items : $this->items()->get();
        $t = ['subtotal' => 0.0, 'discount' => 0.0, 'net' => 0.0, 'tax' => 0.0];
        foreach ($items as $it) {
            $t['subtotal'] += $it->gross();
            $t['discount'] += $it->discountAmount();
            $t['net'] += $it->net();
            $t['tax'] += $it->taxAmount();
        }
        $t = array_map(fn ($v) => round($v, 2), $t);
        $t['total'] = round($t['net'] + $t['tax'], 2);

        return $t;
    }

    /** The invoice total: frozen at issue, computed from the lines for drafts. */
    public function grandTotal(): float
    {
        return $this->total !== null && ! $this->isDraft() ? round((float) $this->total, 2) : $this->totals()['total'];
    }

    /** Amount in USD at the invoice's own rate (today's rate for drafts). */
    public function toBase(float $amount): ?float
    {
        if ($this->fx_rate) {
            return round($amount / (float) $this->fx_rate, 2);
        }
        $base = ExchangeRates::toBase($amount, $this->currency);

        return $base === null ? null : round($base, 2);
    }

    public function totalInBase(): ?float
    {
        return $this->toBase($this->grandTotal());
    }

    /** Issued invoices with something left to pay. */
    public function scopeOpen(Builder $q): Builder
    {
        return $q->where('status', 'issued');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('sort')->orderBy('id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class)->withTrashed();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class)->withTrashed();
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class)->withTrashed();
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id')->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function sourceInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'source_invoice_id')->withTrashed();
    }
}
