<?php

namespace App\Models;

use App\Models\Concerns\VisibleByScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CrmActivity extends Model
{
    use HasFactory, SoftDeletes, VisibleByScope;

    protected $fillable = ['subject_type', 'subject_id', 'type', 'title', 'body', 'due_at', 'remind_at', 'user_id', 'created_by'];

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'remind_at' => 'datetime', 'reminded_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function scopeOwnerColumns(): array
    {
        return ['user_id', 'created_by'];
    }

    public function scopePending(Builder $q): Builder
    {
        return $q->whereNull('completed_at');
    }

    /** Reminders that are due and not yet sent. */
    public function scopeDueReminders(Builder $q): Builder
    {
        return $q->pending()->whereNotNull('remind_at')->whereNull('reminded_at')->where('remind_at', '<=', now());
    }

    public function subject(): MorphTo
    {
        return $this->morphTo()->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }
}
