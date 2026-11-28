<?php

namespace App\Models;

use App\Services\ExchangeRates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Record-level access for projects (permission scopes):
 *   all      -> every project
 *   assigned -> projects the user manages, created or is a member of
 *   own      -> projects the user manages or created
 */
class Project extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUSES = ['planning', 'active', 'on_hold', 'completed', 'cancelled'];

    public const OPEN_STATUSES = ['planning', 'active', 'on_hold'];

    protected $fillable = [
        'name', 'type', 'template', 'client_id', 'quote_id', 'contract_id', 'manager_id', 'status',
        'currency', 'budget', 'start_date', 'due_date', 'description', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'budget' => 'decimal:2',
            'start_date' => 'date', 'due_date' => 'date', 'completed_at' => 'datetime',
        ];
    }

    public function scopeVisibleTo(Builder $query, User $user, string $permission = 'projects.view'): Builder
    {
        $scope = $user->permissionScope($permission);
        if ($scope === 'all') {
            return $query;
        }
        if ($scope === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $q) use ($user, $scope) {
            $q->where('projects.manager_id', $user->id)->orWhere('projects.created_by', $user->id);
            if ($scope === 'assigned') {
                $q->orWhereHas('members', fn ($m) => $m->where('users.id', $user->id));
            }
        });
    }

    public function isVisibleTo(User $user, string $permission = 'projects.view'): bool
    {
        $scope = $user->permissionScope($permission);
        if ($scope === 'all') {
            return true;
        }
        if ($scope === null) {
            return false;
        }
        if ((int) $this->manager_id === $user->id || (int) $this->created_by === $user->id) {
            return true;
        }

        return $scope === 'assigned' && $this->isMember($user);
    }

    public function isMember(User $user): bool
    {
        return $this->relationLoaded('members')
            ? $this->members->contains('id', $user->id)
            : $this->members()->where('users.id', $user->id)->exists();
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->open()->whereNotNull('due_date')->whereDate('due_date', '<', today());
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->due_date && $this->due_date->lt(today());
    }

    /**
     * Progress in percent: the average of the milestones' progress (each from its tasks, or done / not
     * done when it has none). Without milestones: completed top-level tasks / all. Null when neither exists.
     */
    public function progress(): ?int
    {
        $rows = $this->relationLoaded('milestones') ? $this->milestones : $this->milestones()->get();
        if ($rows->isNotEmpty()) {
            return (int) round($rows->sum(fn (ProjectMilestone $m) => $m->progress()) / $rows->count());
        }
        $total = $this->tasks()->topLevel()->count();
        if ($total === 0) {
            return null;
        }

        return (int) round($this->tasks()->topLevel()->whereNotNull('completed_at')->count() / $total * 100);
    }

    public function budgetInBase(): ?float
    {
        if ($this->budget === null) {
            return null;
        }
        $base = ExchangeRates::toBase((float) $this->budget, $this->currency);

        return $base === null ? null : round($base, 2);
    }

    /** Short label for folders and lists: "PRJ-2026-0001 · name". */
    public function label(): string
    {
        return trim(($this->number ? $this->number.' · ' : '').$this->name);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_members')->withPivot('role')->withTimestamps()->orderBy('users.name');
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(ProjectMilestone::class)->orderBy('sort')->orderBy('id');
    }

    public function taskLists(): HasMany
    {
        return $this->hasMany(TaskList::class)->orderBy('sort')->orderBy('id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function risks(): HasMany
    {
        return $this->hasMany(ProjectRisk::class)->latest('id');
    }

    public function issues(): HasMany
    {
        return $this->hasMany(ProjectIssue::class)->latest('id');
    }

    public function changeRequests(): HasMany
    {
        return $this->hasMany(ChangeRequest::class)->orderByDesc('seq');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class)->withTrashed();
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class)->withTrashed();
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id')->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }
}
