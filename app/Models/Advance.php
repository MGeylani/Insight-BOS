<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Petty cash or a salary advance handed to an employee. Open balance = amount − approved expenses − returns. */
class Advance extends Model
{
    protected $fillable = ['number', 'kind', 'user_id', 'account_id', 'date', 'amount', 'currency', 'fx_rate', 'notes', 'status', 'created_by'];

    protected function casts(): array
    {
        return ['date' => 'date', 'amount' => 'decimal:2', 'fx_rate' => 'decimal:6', 'reversed_at' => 'datetime'];
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }

    public function scopePosted(Builder $q): Builder
    {
        return $q->where('status', 'posted');
    }

    public function spent(): float
    {
        return round((float) $this->expenses()->where('status', 'approved')->sum('amount'), 2);
    }

    public function pendingSpent(): float
    {
        return round((float) $this->expenses()->where('status', 'pending')->sum('amount'), 2);
    }

    public function returned(): float
    {
        return round((float) $this->returns()->sum('amount'), 2);
    }

    public function balance(): float
    {
        return $this->isPosted() ? round((float) $this->amount - $this->spent() - $this->returned(), 2) : 0.0;
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(AdvanceReturn::class)->orderBy('date')->orderBy('id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->withTrashed();
    }
}
