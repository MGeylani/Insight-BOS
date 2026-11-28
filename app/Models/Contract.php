<?php

namespace App\Models;

use App\Models\Concerns\VisibleByScope;
use App\Services\ExchangeRates;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contract extends Model
{
    use HasFactory, SoftDeletes, VisibleByScope;

    public const TYPES = ['project', 'retainer'];

    /** Stored statuses. "expired" is derived: an active contract whose end date has passed. */
    public const STATUSES = ['draft', 'sent', 'active', 'completed', 'terminated', 'cancelled'];

    protected $fillable = [
        'type', 'client_id', 'contact_id', 'opportunity_id', 'quote_id', 'title', 'currency',
        'value', 'monthly_fee', 'term_months', 'billing_timing', 'auto_renew', 'notice_days',
        'start_date', 'end_date', 'scope', 'terms', 'notes', 'owner_id', 'created_by', 'source_contract_id',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2', 'monthly_fee' => 'decimal:2', 'fx_rate' => 'decimal:6',
            'auto_renew' => 'boolean', 'term_months' => 'integer', 'notice_days' => 'integer',
            'start_date' => 'date', 'end_date' => 'date', 'issue_date' => 'date', 'signed_on' => 'date', 'terminated_on' => 'date',
            'sent_at' => 'datetime', 'completed_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isRetainer(): bool
    {
        return $this->type === 'retainer';
    }

    public function isExpired(): bool
    {
        return $this->status === 'active' && $this->end_date && $this->end_date->lt(today());
    }

    public function displayStatus(): string
    {
        return $this->isExpired() ? 'expired' : $this->status;
    }

    /** Sum of payment instalments that are not cancelled. */
    public function scheduledTotal(): float
    {
        $rows = $this->relationLoaded('schedules') ? $this->schedules : $this->schedules()->get();

        return round($rows->where('kind', 'payment')->where('status', '!=', 'cancelled')->sum(fn ($s) => (float) $s->amount), 2);
    }

    /** Contract value: the agreed total for a project; every (non-cancelled) monthly instalment for a retainer. */
    public function totalValue(): float
    {
        return $this->isRetainer() ? $this->scheduledTotal() : round((float) $this->value, 2);
    }

    /** Total in USD: the rate frozen at issue, or today's rate for drafts. */
    public function totalInBase(): ?float
    {
        return $this->toBase($this->totalValue());
    }

    public function toBase(float $amount): ?float
    {
        if ($this->fx_rate) {
            return round($amount / (float) $this->fx_rate, 2);
        }
        $base = ExchangeRates::toBase($amount, $this->currency);

        return $base === null ? null : round($base, 2);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(ContractSchedule::class)->orderBy('sort')->orderBy('id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class)->withTrashed();
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class)->withTrashed();
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

    public function sourceContract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'source_contract_id')->withTrashed();
    }

    /** The project this contract is delivered as (set by Services\Projects\Projects::create). */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }
}
