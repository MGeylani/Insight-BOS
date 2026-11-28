<?php

namespace App\Models;

use App\Services\Tasks\Access;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Record-level access for tasks (permission scopes):
 *   all      -> every task
 *   assigned -> "own" + tasks the user watches + tasks of projects the user manages or is a member of
 *   own      -> tasks the user created or is assigned to
 */
class Task extends Model
{
    use SoftDeletes;

    public const PRIORITIES = ['urgent', 'high', 'normal', 'low'];

    protected $fillable = [
        'task_list_id', 'client_id', 'project_id', 'project_milestone_id', 'parent_id', 'task_type_id', 'depth', 'seq', 'title',
        'description', 'description_text', 'status_id', 'priority', 'start_at', 'start_has_time', 'due_at', 'due_has_time',
        'time_estimate_min', 'recurrence', 'recurred_from_id', 'position', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'description' => 'array',
            'recurrence' => 'array',
            'start_at' => 'datetime', 'due_at' => 'datetime', 'completed_at' => 'datetime',
            'start_has_time' => 'boolean', 'due_has_time' => 'boolean', 'is_internal' => 'boolean',
            'depth' => 'integer', 'seq' => 'integer', 'time_estimate_min' => 'integer', 'position' => 'float',
        ];
    }

    /** Ids of projects the user manages, created or is a member of. */
    public static function projectsOf(User $user): QueryBuilder
    {
        return DB::table('projects')->select('id')->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('manager_id', $user->id)->orWhere('created_by', $user->id)
                ->orWhereIn('id', DB::table('project_members')->select('project_id')->where('user_id', $user->id)));
    }

    /**
     * Tasks the user can see (or edit, …): by their permission scope, plus clients / projects / lists shared
     * with them (Services\Tasks\Access). Guests: only shared places and their own tasks, never internal ones.
     */
    public function scopeVisibleTo(Builder $query, User $user, string $permission = 'tasks.view'): Builder
    {
        $scope = $user->permissionScope($permission);
        $levels = Access::levels($permission);
        if ($user->is_guest) {
            return $query->where('tasks.is_internal', false)->where(function (Builder $q) use ($user, $permission, $levels) {
                $q->whereRaw('1 = 0');
                if ($permission === 'tasks.view') {
                    $q->orWhere('tasks.created_by', $user->id)->orWhereIn('tasks.id', DB::table('task_assignees')->select('task_id')->where('user_id', $user->id));
                } elseif ($permission === 'tasks.edit') {
                    $q->orWhere('tasks.created_by', $user->id);
                }
                Access::orTasks($q, $user, $levels);
            });
        }
        if ($scope === 'all') {
            return $query;
        }
        if ($scope === null && ! $levels) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $q) use ($user, $scope, $levels) {
            if ($scope === null) {
                $q->whereRaw('1 = 0');
            } else {
                $q->where('tasks.created_by', $user->id)
                    ->orWhereIn('tasks.id', DB::table('task_assignees')->select('task_id')->where('user_id', $user->id));
                if ($scope === 'assigned') {
                    $q->orWhereIn('tasks.id', DB::table('task_watchers')->select('task_id')->where('user_id', $user->id))
                        ->orWhereIn('tasks.project_id', self::projectsOf($user));
                }
            }
            Access::orTasks($q, $user, $levels);
        });
    }

    public function isVisibleTo(User $user, string $permission = 'tasks.view'): bool
    {
        $scope = $user->permissionScope($permission);
        $levels = Access::levels($permission);
        if ($user->is_guest) {
            if ($this->is_internal) {
                return false;
            }
            $own = (int) $this->created_by === $user->id;
            if (($permission === 'tasks.view' && ($own || $this->assignees()->where('users.id', $user->id)->exists())) || ($permission === 'tasks.edit' && $own)) {
                return true;
            }

            return Access::coversTask($this, $user, $levels);
        }
        if ($scope === 'all') {
            return true;
        }
        if ($scope !== null) {
            if ((int) $this->created_by === $user->id || $this->assignees()->where('users.id', $user->id)->exists()) {
                return true;
            }
            if ($scope === 'assigned' && ($this->watchers()->where('users.id', $user->id)->exists()
                || ($this->project_id && self::projectsOf($user)->where('id', $this->project_id)->exists()))) {
                return true;
            }
        }

        return Access::coversTask($this, $user, $levels);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('tasks.completed_at');
    }

    public function scopeOverdue(Builder $query): Builder
    {
        // A date-only due date is late from the next day; a timed one from that minute.
        return $query->open()->whereNotNull('tasks.due_at')->where(fn ($q) => $q
            ->where(fn ($t) => $t->where('tasks.due_has_time', true)->where('tasks.due_at', '<', now()))
            ->orWhere(fn ($d) => $d->where('tasks.due_has_time', false)->where('tasks.due_at', '<', today())));
    }

    public function scopeTopLevel(Builder $query): Builder
    {
        return $query->whereNull('tasks.parent_id');
    }

    public function isComplete(): bool
    {
        return $this->completed_at !== null;
    }

    /** Past its due date and still open. A date-only due date is late from the next day. */
    public function isOverdue(): bool
    {
        if ($this->completed_at || ! $this->due_at) {
            return false;
        }

        return $this->due_has_time ? $this->due_at->lt(now()) : $this->due_at->copy()->endOfDay()->lt(now());
    }

    /** Custom id shown everywhere: ULF-142. */
    public function code(): string
    {
        $prefix = $this->relationLoaded('client') ? $this->client?->task_prefix : Client::withTrashed()->whereKey($this->client_id)->value('task_prefix');

        return ($prefix ?: 'T').'-'.$this->seq;
    }

    public function list(): BelongsTo
    {
        return $this->belongsTo(TaskList::class, 'task_list_id')->withTrashed();
    }

    /** Other lists the task appears in. */
    public function otherLists(): BelongsToMany
    {
        return $this->belongsToMany(TaskList::class, 'task_list_links')->withPivot('position')->withTimestamps();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(ProjectMilestone::class, 'project_milestone_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id')->withTrashed();
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position')->orderBy('id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(TaskStatus::class, 'status_id');
    }

    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_assignees')->orderBy('users.name');
    }

    public function watchers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_watchers')->orderBy('users.name');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'task_tag')->orderBy('tags.name');
    }

    public function checklists(): HasMany
    {
        return $this->hasMany(Checklist::class)->orderBy('sort')->orderBy('id');
    }

    public function comments(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    public function attachments(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /** Tasks this one waits on (they block it). */
    public function waitingOn(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'task_dependencies', 'task_id', 'depends_on_id')->wherePivot('type', 'waiting')->withPivot('id');
    }

    /** Tasks waiting on this one (it blocks them). */
    public function blocking(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'task_dependencies', 'depends_on_id', 'task_id')->wherePivot('type', 'waiting')->withPivot('id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(TaskType::class, 'task_type_id');
    }

    public function fieldValues(): HasMany
    {
        return $this->hasMany(CustomFieldValue::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }
}
