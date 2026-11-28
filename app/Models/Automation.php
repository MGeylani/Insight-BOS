<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Automation extends Model
{
    protected $fillable = ['name', 'scope_type', 'scope_id', 'trigger', 'trigger_config', 'conditions', 'actions', 'is_active', 'created_by'];

    protected function casts(): array
    {
        return ['trigger_config' => 'array', 'conditions' => 'array', 'actions' => 'array', 'is_active' => 'boolean', 'last_run_at' => 'datetime'];
    }

    /** Does the automation's scope cover this task? */
    public function covers(Task $t): bool
    {
        return match ($this->scope_type) {
            'client' => (int) $this->scope_id === (int) $t->client_id,
            'project' => (int) $this->scope_id === (int) $t->project_id,
            'list' => (int) $this->scope_id === (int) $t->task_list_id || $t->otherLists()->whereKey($this->scope_id)->exists(),
            default => true,
        };
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class)->latest('id');
    }
}
