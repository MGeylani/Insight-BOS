<?php

namespace App\Models;

use App\Models\Concerns\VisibleByScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A receipt (money in from a client) or a refund (money back), in the account's currency.
 * Posted until reversed; never deleted.
 */
class Payment extends Model
{
    use VisibleByScope;

    protected $fillable = [
        'kind', 'status', 'client_id', 'account_id', 'refund_of_id', 'date', 'amount', 'currency', 'fx_rate', 'method', 'reference', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return ['date' => 'date', 'amount' => 'decimal:2', 'fx_rate' => 'decimal:6', 'reversed_at' => 'datetime'];
    }

    /** @return list<string> */
    public function scopeOwnerColumns(): array
    {
        return ['created_by'];
    }

    public function isReceipt(): bool
    {
        return $this->kind === 'receipt';
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }

    public function scopePosted(Builder $q): Builder
    {
        return $q->where('status', 'posted');
    }

    public function amountInBase(): float
    {
        return round((float) $this->amount / max((float) $this->fx_rate, 0.000001), 2);
    }

    /** What is left of a posted receipt after its allocations and refunds (client credit). */
    public function unallocated(): float
    {
        if (! $this->isReceipt() || ! $this->isPosted()) {
            return 0.0;
        }
        $used = (float) $this->allocations()->sum('payment_amount');
        $refunded = (float) self::where('refund_of_id', $this->id)->posted()->sum('amount');

        return round((float) $this->amount - $used - $refunded, 2);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class)->orderBy('id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Payment::class, 'refund_of_id')->orderBy('id');
    }

    public function refundOf(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'refund_of_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function reverser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by')->withTrashed();
    }
}
