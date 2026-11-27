<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectMilestone extends Model
{
    protected $fillable = ['project_id', 'title', 'details', 'due_date', 'status', 'sort'];

    protected function casts(): array
    {
        return ['due_date' => 'date', 'done_at' => 'datetime', 'sort' => 'integer'];
    }

    /** "overdue" is derived: still pending after its due date. */
    public function displayStatus(): string
    {
        return $this->status === 'pending' && $this->due_date && $this->due_date->lt(today()) ? 'overdue' : $this->status;
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    public function doneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by')->withTrashed();
    }
}
