<?php

namespace App\Services\Tasks;

use App\Models\Client;
use App\Models\CustomFieldValue;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskList;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Task, list and project templates. A task is stored with its subtasks, checklists, tags, type, priority,
 * estimate and custom field values; dates become offsets in days from the day the template is applied
 * (start_in_days / due_in_days, measured from the task's creation day).
 */
class Templates
{
    public static function fromTask(Task $task, string $name, User $user): TaskTemplate
    {
        return self::store($name, 'task', ['task' => self::taskPayload($task, $task->created_at ?? now())], $user);
    }

    public static function fromList(TaskList $list, string $name, User $user): TaskTemplate
    {
        return self::store($name, 'list', ['list' => self::listPayload($list)], $user);
    }

    public static function fromProject(Project $project, string $name, User $user): TaskTemplate
    {
        return self::store($name, 'project', ['lists' => $project->taskLists()->get()->map(fn ($l) => self::listPayload($l))->all()], $user);
    }

    /** Create the template's task in a list (as a subtask when a parent is given). */
    public static function applyTask(TaskTemplate $tpl, TaskList $list, User $user, ?Task $parent = null, ?Carbon $day = null): Task
    {
        return DB::transaction(fn () => self::createTask($tpl->payload['task'], $list, $user, $parent, $day ?? today()));
    }

    /** Create the template's list (with its statuses and tasks) under a client / project. */
    public static function applyList(TaskTemplate $tpl, Client $client, ?Project $project, User $user, ?string $name = null, ?Carbon $day = null): TaskList
    {
        return DB::transaction(fn () => self::createList($tpl->payload['list'], $client, $project, $user, $name, $day ?? today()));
    }

    /** @return list<TaskList> */
    public static function applyProject(TaskTemplate $tpl, Project $project, User $user, ?Carbon $day = null): array
    {
        return DB::transaction(fn () => array_map(fn ($l) => self::createList($l, $project->client, $project, $user, null, $day ?? today()), $tpl->payload['lists'] ?? []));
    }

    /** @return array<string, mixed> */
    private static function taskPayload(Task $t, Carbon $anchor): array
    {
        $days = fn ($d) => $d ? (int) $anchor->copy()->startOfDay()->diffInDays($d->copy()->startOfDay(), false) : null;

        return [
            'title' => $t->title,
            'description' => $t->description,
            'type_id' => $t->task_type_id,
            'priority' => $t->priority,
            'estimate' => $t->time_estimate_min,
            'status' => $t->status?->name,
            'start_in_days' => $days($t->start_at), 'start_time' => $t->start_has_time ? $t->start_at->format('H:i') : null,
            'due_in_days' => $days($t->due_at), 'due_time' => $t->due_has_time ? $t->due_at->format('H:i') : null,
            'tags' => $t->tags()->pluck('tags.id')->all(),
            'assignees' => $t->assignees()->pluck('users.id')->all(),
            'fields' => CustomFieldValue::where('task_id', $t->id)->get()->mapWithKeys(fn ($v) => [$v->custom_field_id => $v->value])->all(),
            'checklists' => $t->checklists()->with('items')->get()->map(fn ($c) => [
                'name' => $c->name,
                'items' => $c->items->map(fn ($i) => ['key' => $i->id, 'parent' => $i->parent_id, 'text' => $i->text, 'assignee_id' => $i->assignee_id])->values()->all(),
            ])->all(),
            'children' => $t->children()->get()->map(fn ($c) => self::taskPayload($c, $anchor))->all(),
        ];
    }

    /** @return array<string, mixed> */
    private static function listPayload(TaskList $list): array
    {
        $anchor = Carbon::parse($list->created_at ?? now());

        return [
            'name' => $list->name,
            'statuses' => $list->statuses()->get()->map(fn ($s) => $s->only('name', 'color', 'group'))->all(),
            'tasks' => Task::where('task_list_id', $list->id)->whereNull('parent_id')->orderBy('position')->get()
                ->map(fn ($t) => self::taskPayload($t, $anchor))->all(),
        ];
    }

    private static function createList(array $p, Client $client, ?Project $project, User $user, ?string $name, Carbon $day): TaskList
    {
        $list = TaskLists::create($client, $project, ['name' => $name ?: $p['name']], $user->id, false);
        if (! empty($p['statuses'])) {
            TaskLists::saveStatuses($list->scheme, $p['statuses']);
        }
        foreach ($p['tasks'] ?? [] as $t) {
            self::createTask($t, $list->fresh(), $user, null, $day);
        }
        ActivityLogger::log('tasks.template_applied', $project ?? $client, "قائمة «{$list->name}» من قالب");

        return $list;
    }

    private static function createTask(array $p, TaskList $list, User $user, ?Task $parent, Carbon $day): Task
    {
        $date = fn ($n) => $n === null ? null : $day->copy()->addDays((int) $n)->toDateString();
        $status = ! empty($p['status']) ? $list->statuses()->where('name', $p['status'])->value('id') : null;
        $task = Tasks::create($list, [
            'title' => $p['title'], 'parent_id' => $parent?->id, 'description' => $p['description'] ?? null,
            'task_type_id' => $p['type_id'] ?? null, 'priority' => $p['priority'] ?? 'normal', 'time_estimate_min' => $p['estimate'] ?? null,
            'status_id' => $status,
            'start_date' => $date($p['start_in_days'] ?? null), 'start_time' => $p['start_time'] ?? null,
            'due_date' => $date($p['due_in_days'] ?? null), 'due_time' => $p['due_time'] ?? null,
            'tags' => $p['tags'] ?? [], 'assignees' => ($p['assignees'] ?? []) ?: [$user->id],
        ], $user);
        foreach ($p['fields'] ?? [] as $fid => $value) {
            try {
                CustomFields::setValue($task, \App\Models\CustomField::findOrFail($fid), $value);
            } catch (\Throwable) {
                // The field may not apply in the new place, or no longer exist.
            }
        }
        foreach ($p['checklists'] ?? [] as $cl) {
            $c = Checklists::create($task, $cl['name']);
            $map = [];
            $left = collect($cl['items'] ?? []);
            for ($pass = 0; $pass < 4 && $left->isNotEmpty(); $pass++) {
                $left = $left->reject(function ($i) use (&$map, $c) {
                    if ($i['parent'] && ! isset($map[$i['parent']])) {
                        return false;
                    }
                    $map[$i['key']] = Checklists::addItem($c, ['text' => $i['text'], 'assignee_id' => $i['assignee_id'] ?? null, 'parent_id' => $i['parent'] ? $map[$i['parent']] : null])->id;

                    return true;
                });
            }
        }
        foreach ($p['children'] ?? [] as $child) {
            self::createTask($child, $list, $user, $task, $day);
        }

        return $task;
    }

    private static function store(string $name, string $type, array $payload, User $user): TaskTemplate
    {
        $tpl = TaskTemplate::create(['name' => $name, 'type' => $type, 'payload' => $payload, 'created_by' => $user->id]);
        ActivityLogger::log('tasks.template_saved', $tpl, "حفظ قالب «{$name}»");

        return $tpl;
    }
}
