<?php

namespace App\Models;

use App\Models\Concerns\VisibleByScope;
use App\Services\ExchangeRates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Opportunity extends Model
{
    use HasFactory, SoftDeletes, VisibleByScope;

    public const CLOSED = ['won', 'lost'];

    protected $fillable = [
        'client_id', 'contact_id', 'lead_id', 'title', 'amount', 'currency', 'expected_close_date',
        'owner_id', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'expected_close_date' => 'date', 'closed_at' => 'datetime', 'probability' => 'integer'];
    }

    public static function stageProbability(string $stage): int
    {
        return (int) config("crm.opportunity_stages.$stage.probability", 0);
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereNotIn('stage', self::CLOSED);
    }

    public function isClosed(): bool
    {
        return in_array($this->stage, self::CLOSED, true);
    }

    /** Amount converted to the base currency (USD) at today's rate. Computed, never stored. */
    public function amountInBase(): ?float
    {
        return ExchangeRates::toBase((float) $this->amount, $this->currency);
    }

    public function weightedInBase(): ?float
    {
        $base = $this->amountInBase();

        return $base === null ? null : round($base * $this->probability / 100, 2);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class)->withTrashed();
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class)->withTrashed();
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id')->withTrashed();
    }

    public function stageChanges(): HasMany
    {
        return $this->hasMany(OpportunityStageChange::class)->latest('id');
    }

    public function activities(): MorphMany
    {
        return $this->morphMany(CrmActivity::class, 'subject');
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class)->latest('id');
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class)->latest('id');
    }
}
