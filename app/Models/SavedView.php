<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedView extends Model
{
    public const TYPES = ['list', 'board', 'calendar', 'table', 'gantt'];

    protected $fillable = ['owner_id', 'name', 'type', 'location_type', 'location_id', 'config', 'is_shared', 'sort'];

    protected function casts(): array
    {
        return ['config' => 'array', 'is_shared' => 'boolean'];
    }

    /** Views the user may use here: their own and shared ones, of this location or company-wide. */
    public function scopeAvailableTo(Builder $q, User $user, ?string $type, ?int $id): Builder
    {
        return $q->where(fn ($w) => $w->where('owner_id', $user->id)->orWhere('is_shared', true))
            ->where(fn ($w) => $w->whereNull('location_type')->orWhere(fn ($x) => $x->where('location_type', $type)->where('location_id', $id)));
    }

    public function canManage(User $user): bool
    {
        return (int) $this->owner_id === $user->id || ($this->is_shared && $user->permissionScope('tasks.edit') === 'all');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id')->withTrashed();
    }
}
