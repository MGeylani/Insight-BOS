<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntakeForm extends Model
{
    protected $fillable = ['name', 'description', 'task_list_id', 'token', 'is_public', 'is_active', 'fields', 'defaults', 'confirmation', 'created_by'];

    protected function casts(): array
    {
        return ['fields' => 'array', 'defaults' => 'array', 'is_public' => 'boolean', 'is_active' => 'boolean', 'last_submitted_at' => 'datetime'];
    }

    public function list(): BelongsTo
    {
        return $this->belongsTo(TaskList::class, 'task_list_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function url(): string
    {
        return url("/forms/{$this->token}");
    }
}
