<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A change to an agreed project: extra scope, a cut, a new deadline. Approving it adds
 * cost_impact to the project budget and days_impact to its due date (see Services\Projects\Projects).
 */
class ChangeRequest extends Model
{
    protected $fillable = ['project_id', 'seq', 'title', 'description', 'source', 'cost_impact', 'days_impact', 'created_by'];

    protected function casts(): array
    {
        return [
            'cost_impact' => 'decimal:2', 'budget_before' => 'decimal:2', 'days_impact' => 'integer', 'seq' => 'integer',
            'decided_at' => 'datetime', 'due_before' => 'date',
        ];
    }

    public function code(): string
    {
        return 'CR-'.$this->seq;
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
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
