<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    /** Top-level tasks of this milestone. */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class)->whereNull('parent_id');
    }

    /** Eager-load task counts: ProjectMilestone::withTaskCounts($query). */
    public static function withTaskCounts($query)
    {
        return $query->withCount(['tasks as tasks_total', 'tasks as tasks_done' => fn ($q) => $q->whereNotNull('completed_at')]);
    }

    /** Percent: from its tasks when it has any, otherwise 100 when done by hand and 0 when not. */
    public function progress(): int
    {
        $total = $this->tasks_total ?? $this->tasks()->count();
        if ($total > 0) {
            $done = $this->tasks_done ?? $this->tasks()->whereNotNull('completed_at')->count();

            return (int) round($done / $total * 100);
        }

        return $this->status === 'done' ? 100 : 0;
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
