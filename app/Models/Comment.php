<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Comment extends Model
{
    use SoftDeletes;

    protected $fillable = ['commentable_type', 'commentable_id', 'parent_id', 'user_id', 'body', 'body_text', 'assigned_to', 'is_internal'];

    protected function casts(): array
    {
        return ['body' => 'array', 'resolved_at' => 'datetime', 'edited_at' => 'datetime', 'is_internal' => 'boolean'];
    }

    public function commentable(): MorphTo
    {
        return $this->morphTo()->withTrashed();
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->oldest('id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to')->withTrashed();
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by')->withTrashed();
    }

    public function isOpenAssignment(): bool
    {
        return $this->assigned_to !== null && $this->resolved_at === null;
    }
}
