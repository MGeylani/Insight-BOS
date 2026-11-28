<?php

namespace App\Services\Tasks;

use App\Models\Task;
use App\Models\TaskList;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** One change applied to many tasks. Tasks the user may not change are skipped, not failed. */
class BulkActions
{
    /** action => permission needed on each task */
    public const ACTIONS = [
        'status' => 'tasks.edit', 'priority' => 'tasks.edit', 'due' => 'tasks.edit', 'start' => 'tasks.edit',
        'assignees_set' => 'tasks.assign', 'assignees_add' => 'tasks.assign', 'tags_add' => 'tasks.edit', 'tags_remove' => 'tasks.edit',
        'move' => 'tasks.edit', 'link' => 'tasks.edit', 'archive' => 'tasks.delete',
    ];

    /**
     * @param  list<int>  $ids
     * @return array{done: int, skipped: int}
     */
    public static function run(User $user, array $ids, string $action, mixed $value): array
    {
        $perm = self::ACTIONS[$action] ?? throw ValidationException::withMessages(['action' => 'إجراء غير معروف.']);
        $tasks = Task::whereIn('id', $ids)->visibleTo($user, $perm)->orderBy('depth')->get();
        $skipped = count(array_unique($ids)) - $tasks->count();
        $list = in_array($action, ['move', 'link'], true) ? TaskList::find($value) : null;
        if (in_array($action, ['move', 'link'], true) && (! $list || ! $list->isVisibleTo($user, $action === 'move' ? 'tasks.create' : 'tasks.view'))) {
            throw ValidationException::withMessages(['value' => 'القائمة غير متاحة.']);
        }

        $done = 0;
        DB::transaction(function () use ($tasks, $action, $value, $list, &$done, &$skipped) {
            foreach ($tasks as $task) {
                if ($task->trashed()) {
                    continue; // archived with its parent earlier in this batch
                }
                try {
                    match ($action) {
                        'status' => Tasks::update($task, ['status_id' => (int) $value]),
                        'priority' => Tasks::update($task, ['priority' => (string) $value]),
                        'due' => Tasks::update($task, ['due_date' => $value['date'] ?? null, 'due_time' => $value['time'] ?? null]),
                        'start' => Tasks::update($task, ['start_date' => $value['date'] ?? null, 'start_time' => $value['time'] ?? null]),
                        'assignees_set' => Tasks::syncPeople($task, 'assignees', (array) $value),
                        'assignees_add' => Tasks::syncPeople($task, 'assignees', array_merge($task->assignees()->pluck('users.id')->all(), (array) $value)),
                        'tags_add' => Tasks::syncTags($task, array_merge($task->tags()->pluck('tags.id')->all(), (array) $value)),
                        'tags_remove' => Tasks::syncTags($task, array_diff($task->tags()->pluck('tags.id')->all(), (array) $value)),
                        'move' => $task->parent_id ? throw ValidationException::withMessages(['x' => 'subtask']) : Tasks::moveToList($task, $list),
                        'link' => (int) $task->task_list_id === (int) $list->id ? null : Tasks::addToList($task, $list),
                        'archive' => Task::whereKey($task->id)->exists() ? Tasks::archive($task) : null,
                    };
                    $done++;
                } catch (ValidationException) {
                    $skipped++;
                }
            }
        });
        ActivityLogger::log('tasks.bulk', null, "إجراء جماعي ($action) على $done مهمة");

        return ['done' => $done, 'skipped' => $skipped];
    }
}
