<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One payout to an employee for the out-of-pocket expenses it settles. */
class Reimbursement extends Model
{
    protected $fillable = ['user_id', 'account_id', 'date', 'amount', 'currency', 'fx_rate', 'reference', 'status', 'created_by'];

    protected function casts(): array
    {
        return ['date' => 'date', 'amount' => 'decimal:2', 'fx_rate' => 'decimal:6', 'reversed_at' => 'datetime'];
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
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
