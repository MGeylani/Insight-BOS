<?php

namespace App\Models;

use App\Services\Tasks\Access;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A list of tasks under a client, optionally inside one of its projects.
 *
 * Visibility (tasks.view scope):
 *   all            -> every list
 *   own / assigned -> lists of the internal client, lists the user created, lists holding a task the
 *                     user can see; "assigned" adds the lists of projects the user manages or is a member of.
 */
class TaskList extends Model
{
    use SoftDeletes;

    protected $fillable = ['client_id', 'project_id', 'project_milestone_id', 'status_scheme_id', 'name', 'description', 'sort', 'created_by'];

    protected function casts(): array
    {
        return ['sort' => 'integer'];
    }

    /**
     * Lists the user can see: by their permission scope, plus clients / projects / lists shared with them
     * (Services\Tasks\Access). Guests see only shared lists and lists holding tasks they can see.
     */
    public function scopeVisibleTo(Builder $query, User $user, string $permission = 'tasks.view'): Builder
    {
        $scope = $user->permissionScope($permission);
        $levels = Access::levels($permission);
        if ($scope === 'all' && ! $user->is_guest) {
            return $query;
        }
        if ($scope === null && ! $levels) {
            return $query->whereRaw('1 = 0');
        }
        $visibleTasks = Task::query()->visibleTo($user, $permission);

        return $query->where(function (Builder $q) use ($user, $scope, $levels, $visibleTasks) {
            $q->whereIn('task_lists.id', (clone $visibleTasks)->select('tasks.task_list_id'))
                ->orWhereIn('task_lists.id', TaskListLink::whereIn('task_id', (clone $visibleTasks)->select('tasks.id'))->select('task_list_id'));
            if ($scope !== null && ! $user->is_guest) {
                $q->orWhere('task_lists.created_by', $user->id)
                    ->orWhereIn('task_lists.client_id', Client::where('is_internal', true)->select('id'));
                if ($scope === 'assigned') {
                    $q->orWhereIn('task_lists.project_id', Task::projectsOf($user));
                }
            }
            Access::orLists($q, $user, $levels);
        });
    }

    public function isVisibleTo(User $user, string $permission = 'tasks.view'): bool
    {
        $scope = $user->permissionScope($permission);
        if ($scope === 'all' && ! $user->is_guest) {
            return true;
        }
        if ($scope === null && ! Access::levels($permission)) {
            return false;
        }

        return self::whereKey($this->id)->visibleTo($user, $permission)->exists();
    }

    /** Rename, delete and edit the statuses of this list. */
    public function canManage(User $user): bool
    {
        if ($user->is_guest) {
            return false;
        }
        if ($user->permissionScope('tasks.edit') === 'all') {
            return true;
        }
        if (! $user->hasPermission('tasks.edit')) {
            return false;
        }

        return (int) $this->created_by === $user->id
            || ($this->project_id && (int) $this->project?->manager_id === $user->id);
    }

    /** Create a list under this client / project. */
    public static function canCreateFor(User $user, Client $client, ?Project $project): bool
    {
        if ($user->is_guest) {
            return false;
        }
        if ($user->permissionScope('tasks.edit') === 'all') {
            return true;
        }
        if ($project) {
            return $user->hasPermission('tasks.edit') && (int) $project->manager_id === $user->id;
        }

        return $client->is_internal && $user->hasPermission('tasks.create');
    }

    public function label(): string
    {
        return $this->name;
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

    public function scheme(): BelongsTo
    {
        return $this->belongsTo(StatusScheme::class, 'status_scheme_id');
    }

    public function statuses(): HasMany
    {
        return $this->hasMany(TaskStatus::class, 'status_scheme_id', 'status_scheme_id')->orderBy('sort')->orderBy('id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /** Tasks shown in this list although their home is another list. */
    public function linkedTasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'task_list_links')->withPivot('position')->withTimestamps();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }
}
