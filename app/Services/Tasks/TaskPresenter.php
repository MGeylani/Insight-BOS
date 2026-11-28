<?php

namespace App\Services\Tasks;

use App\Models\Client;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Models\TaskList;
use App\Models\TaskStatus;
use App\Models\TaskType;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Shapes tasks, lists and vocabularies for the Vue pages. */
class TaskPresenter
{
    /** Relations every task row needs. */
    public const ROW_WITH = [
        'status', 'assignees:id,name', 'tags:id,name,color', 'client:id,name,task_prefix', 'project:id,name,number', 'list:id,name',
    ];

    public static function rows(Builder $query, int $limit = 1000): Collection
    {
        $tasks = $query->with(self::ROW_WITH)
            ->withCount(['children', 'children as children_done' => fn ($q) => $q->whereNotNull('completed_at'), 'comments'])
            ->when(empty($query->getQuery()->orders), fn ($q) => $q->orderBy('tasks.position')->orderBy('tasks.id'))
            ->limit($limit)->get();
        $values = CustomFields::valuesFor($tasks);
        $deps = Dependencies::forTasks($tasks->pluck('id'));

        return $tasks->map(fn (Task $t) => self::row($t) + [
            'fields' => (object) ($values[$t->id] ?? []),
            'waiting_on' => $deps[$t->id]['waiting_on'] ?? [],
            'blocked' => $deps[$t->id]['blocked'] ?? false,
        ]);
    }

    /** @return array<string, mixed> */
    public static function row(Task $t): array
    {
        return [
            'id' => $t->id,
            'code' => $t->code(),
            'title' => $t->title,
            'parent_id' => $t->parent_id,
            'type_id' => $t->task_type_id,
            'depth' => $t->depth,
            'list_id' => $t->task_list_id,
            'list' => $t->list?->only('id', 'name'),
            'client' => $t->client?->only('id', 'name'),
            'project' => $t->project?->only('id', 'name', 'number'),
            'milestone_id' => $t->project_milestone_id,
            'status' => $t->status?->toUi(),
            'priority' => $t->priority,
            'start' => self::date($t->start_at, $t->start_has_time),
            'due' => self::date($t->due_at, $t->due_has_time),
            'overdue' => $t->isOverdue(),
            'completed' => $t->completed_at !== null,
            'completed_at' => $t->completed_at?->toIso8601String(),
            'estimate' => $t->time_estimate_min,
            'recurrence' => $t->recurrence,
            'recurrence_label' => $t->recurrence ? Recurrence::label($t->recurrence) : null,
            'assignees' => $t->assignees->map->only('id', 'name')->values(),
            'tags' => $t->tags->map->only('id', 'name', 'color')->values(),
            'children_count' => (int) ($t->children_count ?? 0),
            'children_done' => (int) ($t->children_done ?? 0),
            'comments_count' => (int) ($t->comments_count ?? 0),
            'position' => $t->position,
            'created_at' => $t->created_at?->toIso8601String(),
        ];
    }

    /** "2026-12-01" for a date, "2026-12-01T14:30" (local time) for a date with time. */
    public static function date($value, bool $hasTime): ?string
    {
        return $value ? $value->format($hasTime ? 'Y-m-d\TH:i' : 'Y-m-d') : null;
    }

    /**
     * Sidebar tree of everything the user can see: client -> (lists, projects -> lists).
     *
     * @return list<array<string, mixed>>
     */
    public static function tree(User $user): array
    {
        $lists = TaskList::visibleTo($user)
            ->with(['client:id,name,is_internal,task_prefix', 'project:id,name,number,status'])
            ->withCount(['tasks as open_count' => fn ($q) => $q->whereNull('completed_at')->whereNull('parent_id')])
            ->orderBy('sort')->orderBy('id')->get();

        return $lists->groupBy('client_id')->map(function ($rows) {
            $client = $rows->first()->client;

            return [
                'id' => $client?->id,
                'name' => $client?->name,
                'prefix' => $client?->task_prefix,
                'internal' => (bool) $client?->is_internal,
                'lists' => $rows->whereNull('project_id')->map(fn ($l) => self::listNode($l))->values(),
                'projects' => $rows->whereNotNull('project_id')->groupBy('project_id')->map(fn ($pl) => [
                    'id' => $pl->first()->project?->id,
                    'name' => $pl->first()->project?->name,
                    'number' => $pl->first()->project?->number,
                    'status' => $pl->first()->project?->status,
                    'lists' => $pl->map(fn ($l) => self::listNode($l))->values(),
                ])->values(),
            ];
        })->sortBy(fn ($c) => [$c['internal'] ? 0 : 1, $c['name']])->values()->all();
    }

    /** @return array<string, mixed> */
    private static function listNode(TaskList $l): array
    {
        return ['id' => $l->id, 'name' => $l->name, 'open' => (int) $l->open_count, 'milestone_id' => $l->project_milestone_id];
    }

    /** Statuses for a set of lists (deduplicated by name for multi-list views, keeping group order). */
    public static function statusesFor(Collection $listIds): Collection
    {
        $order = array_flip(TaskStatus::GROUPS);

        return TaskStatus::whereIn('status_scheme_id', TaskList::withTrashed()->whereIn('id', $listIds)->select('status_scheme_id'))
            ->orderBy('sort')->get()
            ->unique('name')
            ->sortBy(fn ($s) => [$order[$s->group] ?? 9, $s->sort])
            ->map->toUi()->values();
    }

    /** Vocabularies shared by every task page. @return array<string, mixed> */
    public static function vocab(): array
    {
        return [
            'priorities' => config('tasks.priorities'),
            'groups' => config('tasks.status_groups'),
            'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'tags' => Tag::orderBy('name')->get(['id', 'name', 'color']),
            'types' => TaskType::with('fields:id')->orderBy('sort')->orderBy('id')->get()->map->toUi()->values(),
        ];
    }

    /** Where a location points to, for headers and breadcrumbs. @return array<string, mixed>|null */
    public static function location(array $loc): ?array
    {
        if ($id = (int) ($loc['list'] ?? 0)) {
            $l = TaskList::with(['client:id,name', 'project:id,name,number'])->find($id);

            return $l ? ['type' => 'list', 'id' => $l->id, 'name' => $l->name, 'client' => $l->client?->only('id', 'name'),
                'project' => $l->project?->only('id', 'name', 'number'), 'milestone_id' => $l->project_milestone_id, 'description' => $l->description] : null;
        }
        if ($id = (int) ($loc['project'] ?? 0)) {
            $p = Project::with('client:id,name')->find($id);

            return $p ? ['type' => 'project', 'id' => $p->id, 'name' => $p->name, 'number' => $p->number, 'client' => $p->client?->only('id', 'name')] : null;
        }
        if ($id = (int) ($loc['client'] ?? 0)) {
            $c = Client::find($id);

            return $c ? ['type' => 'client', 'id' => $c->id, 'name' => $c->name, 'prefix' => $c->task_prefix] : null;
        }

        return null;
    }
}
