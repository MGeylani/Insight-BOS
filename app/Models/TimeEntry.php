<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimeEntry extends Model
{
    protected $fillable = ['user_id', 'task_id', 'project_id', 'client_id', 'started_at', 'ended_at', 'minutes', 'billable', 'cost_rate', 'note', 'source'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime', 'ended_at' => 'datetime', 'billable' => 'boolean',
            'minutes' => 'integer', 'cost_rate' => 'decimal:2',
        ];
    }

    public function scopeRunning(Builder $q): Builder
    {
        return $q->whereNull('ended_at');
    }

    public function scopeFinished(Builder $q): Builder
    {
        return $q->whereNotNull('ended_at');
    }

    /** Minutes so far (a running timer counts up to now). */
    public function liveMinutes(): int
    {
        return $this->ended_at ? $this->minutes : (int) floor($this->started_at->diffInSeconds(now(), true) / 60);
    }

    public function cost(): ?float
    {
        return $this->cost_rate === null ? null : round($this->minutes / 60 * (float) $this->cost_rate, 2);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class)->withTrashed();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }
}
