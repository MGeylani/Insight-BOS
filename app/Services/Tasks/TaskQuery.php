<?php

namespace App\Services\Tasks;

use App\Models\Client;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Builds the task query for every view (list, board, calendar, table, export) from a location and a
 * filter set, so all views show the same tasks for the same settings.
 *
 * Location: list | project | client (one id each) or nothing (everything the user can see).
 * Filters:  search, assignees[] ('me', 'none' or ids), statuses[] (ids), groups[], priorities[], tags[],
 *           due (overdue | today | week | next7 | none), closed (include complete tasks), subtasks (bool).
 */
class TaskQuery
{
    public const FILTER_KEYS = ['search', 'assignees', 'statuses', 'groups', 'priorities', 'tags', 'due', 'closed', 'created_by'];

    /**
     * @param  array<string, mixed>  $location
     * @param  array<string, mixed>  $filters
     */
    public static function build(User $user, array $location, array $filters): Builder
    {
        $q = Task::query()->visibleTo($user)->select('tasks.*');

        if ($list = (int) ($location['list'] ?? 0)) {
            $q->where(fn ($w) => $w->where('tasks.task_list_id', $list)
                ->orWhereIn('tasks.id', DB::table('task_list_links')->select('task_id')->where('task_list_id', $list)));
        } elseif ($project = (int) ($location['project'] ?? 0)) {
            $q->where('tasks.project_id', $project);
        } elseif ($client = (int) ($location['client'] ?? 0)) {
            $q->where('tasks.client_id', $client);
        }

        return self::filter($q, $user, $filters);
    }

    /** @param array<string, mixed> $f */
    public static function filter(Builder $q, User $user, array $f): Builder
    {
        if ($s = trim((string) ($f['search'] ?? ''))) {
            if (preg_match('/^([A-Za-z0-9]+)-(\d+)$/', $s, $m)) {
                $clientId = Client::withTrashed()->where('task_prefix', strtoupper($m[1]))->value('id');
                $q->where('tasks.client_id', $clientId ?? 0)->where('tasks.seq', (int) $m[2]);
            } else {
                $q->where(fn ($w) => $w->where('tasks.title', 'like', "%$s%")->orWhere('tasks.description_text', 'like', "%$s%"));
            }
        }

        $assignees = self::ids($f['assignees'] ?? [], $user);
        if ($assignees || in_array('none', (array) ($f['assignees'] ?? []), true)) {
            $q->where(function ($w) use ($assignees, $f) {
                if ($assignees) {
                    $w->whereIn('tasks.id', DB::table('task_assignees')->select('task_id')->whereIn('user_id', $assignees));
                }
                if (in_array('none', (array) ($f['assignees'] ?? []), true)) {
                    $w->orWhereNotIn('tasks.id', DB::table('task_assignees')->select('task_id'));
                }
            });
        }
        if ($creators = self::ids($f['created_by'] ?? [], $user)) {
            $q->whereIn('tasks.created_by', $creators);
        }
        if ($statuses = array_filter(array_map('intval', (array) ($f['statuses'] ?? [])))) {
            $q->whereIn('tasks.status_id', $statuses);
        }
        if ($groups = array_intersect((array) ($f['groups'] ?? []), ['not_started', 'active', 'done', 'closed'])) {
            $q->whereIn('tasks.status_id', DB::table('task_statuses')->select('id')->whereIn('group', $groups));
        }
        if ($priorities = array_intersect((array) ($f['priorities'] ?? []), Task::PRIORITIES)) {
            $q->whereIn('tasks.priority', $priorities);
        }
        if ($tags = array_filter(array_map('intval', (array) ($f['tags'] ?? [])))) {
            $q->whereIn('tasks.id', DB::table('task_tag')->select('task_id')->whereIn('tag_id', $tags));
        }
        match ($f['due'] ?? null) {
            'overdue' => $q->overdue(),
            'today' => $q->whereDate('tasks.due_at', today()),
            'week' => $q->whereBetween('tasks.due_at', [today()->startOfWeek(\Carbon\Carbon::SATURDAY), today()->endOfWeek(\Carbon\Carbon::FRIDAY)]),
            'next7' => $q->whereBetween('tasks.due_at', [today(), today()->addDays(7)->endOfDay()]),
            'none' => $q->whereNull('tasks.due_at'),
            default => null,
        };
        if (! filter_var($f['closed'] ?? false, FILTER_VALIDATE_BOOL) && empty($groups) && empty($statuses)) {
            $q->whereNull('tasks.completed_at');
        }

        return $q;
    }

    /** Order rows: manual (drag order) by default. */
    public static function sort(Builder $q, string $sort, string $dir): Builder
    {
        $dir = $dir === 'desc' ? 'desc' : 'asc';
        match ($sort) {
            'due' => $q->orderByRaw('tasks.due_at is null')->orderBy('tasks.due_at', $dir),
            'priority' => $q->orderByRaw("case tasks.priority when 'urgent' then 0 when 'high' then 1 when 'normal' then 2 else 3 end ".$dir),
            'created' => $q->orderBy('tasks.created_at', $dir),
            'updated' => $q->orderBy('tasks.updated_at', $dir),
            'title' => $q->orderBy('tasks.title', $dir),
            default => null,
        };

        return $q->orderBy('tasks.position')->orderBy('tasks.id');
    }

    /** Clean filter input from a request or a saved view. @return array<string, mixed> */
    public static function clean(array $input): array
    {
        $out = [];
        foreach (self::FILTER_KEYS as $k) {
            $v = $input[$k] ?? null;
            if ($v === null || $v === '' || $v === []) {
                continue;
            }
            $out[$k] = match ($k) {
                'search', 'due' => mb_substr((string) $v, 0, 120),
                'closed' => filter_var($v, FILTER_VALIDATE_BOOL),
                default => array_values(array_slice(array_map(fn ($x) => is_numeric($x) ? (int) $x : (string) $x, (array) $v), 0, 100)),
            };
        }

        return $out;
    }

    /** @return list<int> */
    private static function ids(mixed $values, User $user): array
    {
        $ids = [];
        foreach ((array) $values as $v) {
            if ($v === 'me') {
                $ids[] = $user->id;
            } elseif (is_numeric($v)) {
                $ids[] = (int) $v;
            }
        }

        return array_values(array_unique($ids));
    }
}
