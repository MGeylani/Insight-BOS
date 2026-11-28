<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Money paid to a vendor from an account, settling its credit bills. Never deleted; reversed. */
class VendorPayment extends Model
{
    protected $fillable = ['number', 'vendor_id', 'account_id', 'date', 'amount', 'currency', 'fx_rate', 'method', 'reference', 'notes', 'status', 'created_by'];

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

    public function amountInBase(): float
    {
        return round((float) $this->amount / max((float) $this->fx_rate, 0.000001), 2);
    }

    /** What is not applied to bills yet (advance to the vendor). */
    public function unallocated(): float
    {
        return $this->isPosted() ? round((float) $this->amount - (float) $this->allocations()->sum('payment_amount'), 2) : 0.0;
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(VendorPaymentAllocation::class)->orderBy('id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class)->withTrashed();
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->withTrashed();
    }
}
