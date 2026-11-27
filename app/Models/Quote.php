<?php

namespace App\Models;

use App\Models\Concerns\VisibleByScope;
use App\Services\ExchangeRates;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Quote extends Model
{
    use HasFactory, SoftDeletes, VisibleByScope;

    /** Stored statuses. "expired" is derived: a sent quote whose validity date has passed. */
    public const STATUSES = ['draft', 'sent', 'accepted', 'rejected', 'cancelled'];

    protected $fillable = [
        'client_id', 'opportunity_id', 'contact_id', 'title', 'valid_until', 'currency',
        'intro', 'terms', 'notes', 'owner_id', 'created_by', 'source_quote_id',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date', 'valid_until' => 'date', 'fx_rate' => 'decimal:6',
            'sent_at' => 'datetime', 'accepted_at' => 'datetime', 'rejected_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isExpired(): bool
    {
        return $this->status === 'sent' && $this->valid_until && $this->valid_until->lt(today());
    }

    /** Status shown to people: the stored one, or "expired". */
    public function displayStatus(): string
    {
        return $this->isExpired() ? 'expired' : $this->status;
    }

    /**
     * @return array{subtotal: float, discount: float, net: float, tax: float, total: float}
     */
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

    /** Total in USD: the rate frozen at issue, or today's rate for drafts. */
    public function totalInBase(): ?float
    {
        $total = $this->totals()['total'];
        if ($this->fx_rate) {
            return round($total / (float) $this->fx_rate, 2);
        }
        $base = ExchangeRates::toBase($total, $this->currency);

        return $base === null ? null : round($base, 2);
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuoteItem::class)->orderBy('sort')->orderBy('id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class)->withTrashed();
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class)->withTrashed();
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id')->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function sourceQuote(): BelongsTo
    {
        return $this->belongsTo(Quote::class, 'source_quote_id')->withTrashed();
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class)->latest('id');
    }

    /** The project this quote turned into (set by Services\Projects\Projects::create). */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }
}
