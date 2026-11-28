<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A cost. Stored statuses: pending | approved | rejected | cancelled. paid_by decides where the money came from:
 * account (out of an account on its date), employee (owed to them until reimbursed), advance (from their petty cash),
 * credit (a vendor bill, owed until vendor payments settle it).
 */
class Expense extends Model
{
    protected $fillable = [
        'date', 'category_id', 'description', 'amount', 'currency', 'fx_rate', 'paid_by', 'account_id', 'advance_id', 'vendor_id',
        'vendor_ref', 'due_date', 'project_id', 'client_id', 'user_id', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return ['date' => 'date', 'due_date' => 'date', 'amount' => 'decimal:2', 'fx_rate' => 'decimal:6', 'decided_at' => 'datetime'];
    }

    public const PAID_BY = ['account', 'employee', 'advance', 'credit'];

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function scopeApproved(Builder $q): Builder
    {
        return $q->where('expenses.status', 'approved');
    }

    /**
     * Who sees it: finance.view (all) or approvers (all) see everything; finance.approve assigned also sees
     * expenses of projects they manage; everyone sees what they incurred or entered.
     */
    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        if ($user->permissionScope('finance.view') === 'all' || $user->permissionScope('finance.approve') === 'all') {
            return $q;
        }

        return $q->where(function (Builder $q) use ($user) {
            $q->where('expenses.user_id', $user->id)->orWhere('expenses.created_by', $user->id);
            if ($user->permissionScope('finance.approve') === 'assigned') {
                $q->orWhereIn('expenses.project_id', Project::where('manager_id', $user->id)->select('id'));
            }
        });
    }

    public function isVisibleTo(User $user, string $permission = 'finance.view'): bool
    {
        return self::query()->visibleTo($user)->whereKey($this->id)->exists();
    }

    public function amountInBase(): float
    {
        return round((float) $this->amount / max((float) $this->fx_rate, 0.000001), 2);
    }

    /** Credit bills: paid so far through vendor payments (in the bill's currency). */
    public function paidAmount(): float
    {
        if (array_key_exists('paid_sum', $this->attributes)) {
            return round((float) $this->attributes['paid_sum'], 2);
        }

        return round((float) $this->allocations()->sum('amount'), 2);
    }

    public function owed(): float
    {
        return $this->paid_by === 'credit' && $this->isApproved() ? round((float) $this->amount - $this->paidAmount(), 2) : 0.0;
    }

    /** pending | approved | rejected | cancelled, plus for approved: unpaid / partial / paid (credit), owed / reimbursed (employee). */
    public function settlement(): ?string
    {
        if (! $this->isApproved()) {
            return null;
        }

        return match ($this->paid_by) {
            'credit' => ($o = $this->owed()) <= 0 ? 'paid' : ($o < (float) $this->amount ? 'partial' : ($this->due_date && $this->due_date->lt(today()) ? 'overdue' : 'unpaid')),
            'employee' => $this->reimbursement_id ? 'reimbursed' : 'to_reimburse',
            default => null,
        };
    }

    public function scopeWithPaid(Builder $q): Builder
    {
        return $q->withSum('allocations as paid_sum', 'amount');
    }

    /** SQL: approved credit bills with something still owed. */
    public function scopeUnpaidBills(Builder $q): Builder
    {
        return $q->approved()->where('expenses.paid_by', 'credit')
            ->whereRaw('(select coalesce(sum(a.amount), 0) from vendor_payment_allocations a where a.expense_id = expenses.id) < expenses.amount');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(VendorPaymentAllocation::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id')->withTrashed();
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class)->withTrashed();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->withTrashed();
    }

    public function advance(): BelongsTo
    {
        return $this->belongsTo(Advance::class);
    }

    public function reimbursement(): BelongsTo
    {
        return $this->belongsTo(Reimbursement::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by')->withTrashed();
    }
}
