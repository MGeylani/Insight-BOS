<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProofComment extends Model
{
    protected $fillable = ['attachment_id', 'task_id', 'parent_id', 'user_id', 'author_name', 'body', 'x', 'y', 'at_seconds'];

    protected function casts(): array
    {
        return ['x' => 'float', 'y' => 'float', 'at_seconds' => 'float', 'resolved_at' => 'datetime'];
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->oldest('id');
    }

    public function authorName(): string
    {
        return $this->user?->name ?? $this->author_name ?? '—';
    }
}
