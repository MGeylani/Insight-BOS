<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of a contract's schedule: a payment instalment (amount) or a delivery milestone.
 * Payments are settled by invoices (phase 4); milestones are marked done by hand.
 */
class ContractSchedule extends Model
{
    protected $fillable = [
        'contract_id', 'kind', 'title', 'details', 'due_date', 'amount', 'period_start', 'period_end', 'status', 'sort',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2', 'due_date' => 'date', 'period_start' => 'date', 'period_end' => 'date', 'done_at' => 'datetime',
        ];
    }

    public function isPayment(): bool
    {
        return $this->kind === 'payment';
    }

    /** "overdue" is derived: still pending after its due date. */
    public function displayStatus(): string
    {
        return $this->status === 'pending' && $this->due_date && $this->due_date->lt(today()) ? 'overdue' : $this->status;
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class)->withTrashed();
    }

    public function doneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by')->withTrashed();
    }
}
