<?php

namespace App\Services\Tasks;

use App\Models\ProjectMilestone;
use App\Models\Tag;
use App\Models\Task;
use App\Models\TaskList;
use App\Models\TaskStatus;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\RichText;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only place that creates, changes, moves and archives tasks. Every change is written to the
 * audit log with the task as subject; that log is the task's activity history.
 *
 * Completion: a task is complete while its status is in the done or closed group (completed_at).
 * A milestone whose list has tasks is done when all its top-level tasks are complete.
 */
class Tasks
{
    /** Labels used in the activity history. */
    public const FIELD_LABELS = [
        'title' => 'العنوان', 'status_id' => 'الحالة', 'priority' => 'الأولوية', 'start_at' => 'تاريخ البدء',
        'due_at' => 'تاريخ التسليم', 'time_estimate_min' => 'التقدير الزمني', 'description' => 'الوصف',
        'task_list_id' => 'القائمة', 'project_milestone_id' => 'المرحلة', 'parent_id' => 'المهمة الأم',
    ];

    /** @param array<string, mixed> $data */
    public static function create(TaskList $list, array $data, User $user): Task
    {
        $parent = ! empty($data['parent_id']) ? Task::findOrFail($data['parent_id']) : null;
        if ($parent) {
            $list = $parent->list;
            if ($parent->depth >= (int) config('tasks.max_depth')) {
                throw ValidationException::withMessages(['parent_id' => 'المهام الفرعية تصل إلى ثلاثة مستويات فقط.']);
            }
        }
        $status = self::statusFor($list, $data['status_id'] ?? null);

        return DB::transaction(function () use ($list, $data, $user, $parent, $status) {
            $client = $list->client()->first();
            $doc = array_key_exists('description', $data) ? RichText::normalize($data['description']) : null;
            $task = new Task([
                'task_list_id' => $list->id,
                'client_id' => $list->client_id,
                'project_id' => $list->project_id,
                'project_milestone_id' => $parent?->project_milestone_id ?? $list->project_milestone_id,
                'parent_id' => $parent?->id,
                'depth' => $parent ? $parent->depth + 1 : 0,
                'seq' => TaskLists::nextSeq($client),
                'title' => trim($data['title']),
                'description' => $doc,
                'description_text' => $doc ? RichText::toPlain($doc) : null,
                'status_id' => $status->id,
                'priority' => $data['priority'] ?? 'normal',
                'time_estimate_min' => $data['time_estimate_min'] ?? null,
                'position' => self::nextPosition($list->id, $parent?->id),
                'created_by' => $user->id,
            ]);
            self::fillDates($task, $data);
            $task->completed_at = $status->isComplete() ? now() : null;
            $task->save();

            $assignees = $data['assignees'] ?? [$user->id];
            $task->assignees()->sync(self::activeUsers($assignees));
            $task->watchers()->sync(self::activeUsers(array_merge($data['watchers'] ?? [], [$user->id])));
            if (! empty($data['tags'])) {
                $task->tags()->sync(Tag::whereIn('id', $data['tags'])->pluck('id'));
            }
            $task->setRelation('client', $client);

            ActivityLogger::log('tasks.created', $task, ($parent ? 'إنشاء مهمة فرعية ' : 'إنشاء المهمة ').$task->code().': '.$task->title);
            self::syncMilestone($task->project_milestone_id);

            return $task;
        });
    }

    /**
     * Change any subset of the task's own fields. People, tags and lists have their own methods.
     *
     * @param  array<string, mixed>  $data
     */
    public static function update(Task $task, array $data): Task
    {
        return DB::transaction(function () use ($task, $data) {
            $oldMilestone = $task->project_milestone_id;
            if (array_key_exists('title', $data)) {
                $task->title = trim($data['title']);
            }
            if (array_key_exists('description', $data)) {
                $doc = RichText::normalize($data['description']);
                $task->description = $doc;
                $task->description_text = $doc ? RichText::toPlain($doc) : null;
            }
            foreach (['priority', 'time_estimate_min'] as $f) {
                if (array_key_exists($f, $data)) {
                    $task->$f = $f === 'priority' ? ($data[$f] ?: 'normal') : $data[$f];
                }
            }
            if (array_key_exists('project_milestone_id', $data)) {
                $mid = $data['project_milestone_id'] ?: null;
                if ($mid && (! $task->project_id || ! ProjectMilestone::where('project_id', $task->project_id)->whereKey($mid)->exists())) {
                    throw ValidationException::withMessages(['project_milestone_id' => 'المرحلة لا تتبع مشروع المهمة.']);
                }
                $task->project_milestone_id = $mid;
            }
            self::fillDates($task, $data);
            if (array_key_exists('status_id', $data) && (int) $data['status_id'] !== (int) $task->status_id) {
                self::applyStatus($task, self::statusFor($task->list, $data['status_id']));
            }

            $entry = self::describeChanges($task, $task->getDirty());
            $task->save();
            if ($entry) {
                ActivityLogger::log('tasks.updated', $task, $entry[0], $entry[1]);
            }
            if ($task->wasChanged(['completed_at', 'project_milestone_id'])) {
                self::syncMilestone($task->project_milestone_id);
                if ((int) $oldMilestone !== (int) $task->project_milestone_id) {
                    self::syncMilestone($oldMilestone);
                }
            }

            return $task;
        });
    }

    public static function setStatus(Task $task, TaskStatus $status): Task
    {
        return self::update($task, ['status_id' => $status->id]);
    }

    /** @param list<int> $ids */
    public static function syncPeople(Task $task, string $relation, array $ids): void
    {
        abort_unless(in_array($relation, ['assignees', 'watchers'], true), 400);
        $result = $task->$relation()->sync(self::activeUsers($ids));
        if ($relation === 'assignees' && $result['attached']) {
            // Assignees follow the task too.
            $task->watchers()->syncWithoutDetaching($result['attached']);
        }
        if ($result['attached'] || $result['detached']) {
            $names = fn ($ids) => User::whereIn('id', $ids)->pluck('name')->implode('، ');
            $label = $relation === 'assignees' ? 'المسؤولين' : 'المتابعين';
            $parts = array_filter([
                $result['attached'] ? 'إضافة '.$names($result['attached']) : null,
                $result['detached'] ? 'إزالة '.$names($result['detached']) : null,
            ]);
            ActivityLogger::log("tasks.$relation", $task, "تحديث $label: ".implode(' · ', $parts), [
                'attached' => $result['attached'], 'detached' => $result['detached'],
            ]);
        }
        $task->unsetRelation($relation);
    }

    /** @param list<int> $ids */
    public static function syncTags(Task $task, array $ids): void
    {
        $result = $task->tags()->sync(Tag::whereIn('id', $ids)->pluck('id'));
        if ($result['attached'] || $result['detached']) {
            ActivityLogger::log('tasks.tags', $task, 'تحديث الوسوم: '.($task->tags()->pluck('name')->implode('، ') ?: '—'));
        }
        $task->unsetRelation('tags');
    }

    /** Move the task (and its subtasks) to another home list of the same client. */
    public static function moveToList(Task $task, TaskList $list): Task
    {
        if ($task->parent_id) {
            throw ValidationException::withMessages(['task_list_id' => 'المهمة الفرعية تتبع قائمة مهمتها الأم.']);
        }
        if ((int) $list->client_id !== (int) $task->client_id) {
            throw ValidationException::withMessages(['task_list_id' => 'تُنقل المهمة إلى قائمة من العميل نفسه فقط (معرّفها مرتبط بالعميل).']);
        }
        if ((int) $list->id === (int) $task->task_list_id) {
            return $task;
        }

        return DB::transaction(function () use ($task, $list) {
            $from = $task->list;
            $oldMilestone = $task->project_milestone_id;
            $ids = self::descendantIds($task);
            $ids[] = $task->id;
            $statusMap = [];
            foreach (Task::whereIn('id', $ids)->with('status')->get() as $t) {
                $target = self::matchStatus($list, $t->status);
                $statusMap[$t->id] = $target;
                $t->forceFill([
                    'task_list_id' => $list->id, 'project_id' => $list->project_id,
                    'project_milestone_id' => $list->project_milestone_id,
                    'status_id' => $target->id,
                    'completed_at' => $target->isComplete() ? ($t->completed_at ?? now()) : null,
                ]);
                if ($t->id === $task->id) {
                    $t->position = self::nextPosition($list->id, null);
                }
                $t->save();
            }
            // A task never appears twice in the same list.
            DB::table('task_list_links')->where('task_id', $task->id)->where('task_list_id', $list->id)->delete();
            $task->refresh();
            ActivityLogger::log('tasks.moved', $task, "نقل المهمة من «{$from?->name}» إلى «{$list->name}»", [
                'task_list_id' => ['from' => $from?->id, 'to' => $list->id],
            ]);
            self::syncMilestone($oldMilestone);
            self::syncMilestone($task->project_milestone_id);

            return $task;
        });
    }

    /** Show the task in another list as well (any client: e.g. an agency-wide "shoots this week" list). */
    public static function addToList(Task $task, TaskList $list): void
    {
        if ((int) $list->id === (int) $task->task_list_id) {
            throw ValidationException::withMessages(['task_list_id' => 'المهمة موجودة أصلاً في هذه القائمة.']);
        }
        if ($task->otherLists()->whereKey($list->id)->exists()) {
            return;
        }
        $pos = (float) DB::table('task_list_links')->where('task_list_id', $list->id)->max('position') + 1024;
        $task->otherLists()->attach($list->id, ['position' => $pos]);
        ActivityLogger::log('tasks.linked', $task, "إضافة المهمة إلى القائمة «{$list->name}»");
    }

    public static function removeFromList(Task $task, TaskList $list): void
    {
        if ($task->otherLists()->detach($list->id)) {
            ActivityLogger::log('tasks.unlinked', $task, "إزالة المهمة من القائمة «{$list->name}»");
        }
    }

    /** Change the parent (or make it top level). Depth must stay within three levels. */
    public static function setParent(Task $task, ?Task $parent): Task
    {
        if ($parent) {
            if ((int) $parent->client_id !== (int) $task->client_id) {
                throw ValidationException::withMessages(['parent_id' => 'المهمة الأم من عميل آخر.']);
            }
            if ($parent->id === $task->id || in_array($parent->id, self::descendantIds($task), true)) {
                throw ValidationException::withMessages(['parent_id' => 'لا يمكن جعل المهمة فرعية لنفسها أو لإحدى مهامها الفرعية.']);
            }
            if ($parent->depth + 1 + self::subtreeHeight($task) > (int) config('tasks.max_depth')) {
                throw ValidationException::withMessages(['parent_id' => 'المهام الفرعية تصل إلى ثلاثة مستويات فقط.']);
            }
        }

        return DB::transaction(function () use ($task, $parent) {
            if ($parent && (int) $parent->task_list_id !== (int) $task->task_list_id) {
                $task->parent_id = null;
                self::moveToList($task, $parent->list);
                $task->refresh();
            }
            $old = $task->parent;
            $task->parent_id = $parent?->id;
            $task->depth = $parent ? $parent->depth + 1 : 0;
            $task->project_milestone_id = $parent?->project_milestone_id ?? $task->project_milestone_id;
            $task->position = self::nextPosition($task->task_list_id, $parent?->id);
            $task->save();
            self::refreshDepths($task);
            ActivityLogger::log('tasks.parent', $task, $parent ? "جعلها فرعية للمهمة {$parent->code()}" : 'جعلها مهمة رئيسية'.($old ? " (كانت فرعية لـ {$old->code()})" : ''));
            self::syncMilestone($task->project_milestone_id);

            return $task;
        });
    }

    /** Place the task between two siblings (fractional position, nothing else is renumbered). */
    public static function reorder(Task $task, ?float $before, ?float $after): void
    {
        $task->position = match (true) {
            $before !== null && $after !== null => ($before + $after) / 2,
            $before !== null => $before + 1024,
            $after !== null => $after - 1024,
            default => $task->position,
        };
        $task->saveQuietly();
    }

    public static function archive(Task $task): void
    {
        DB::transaction(function () use ($task) {
            $ids = self::descendantIds($task);
            Task::whereIn('id', $ids)->update(['deleted_at' => now()]);
            $task->delete();
            ActivityLogger::log('tasks.archived', $task, "أرشفة المهمة {$task->code()}: {$task->title}".($ids ? ' مع '.count($ids).' مهمة فرعية' : ''));
            self::syncMilestone($task->project_milestone_id);
        });
    }

    public static function restore(Task $task): void
    {
        DB::transaction(function () use ($task) {
            $task->restore();
            Task::withTrashed()->whereIn('id', self::descendantIds($task, true))->update(['deleted_at' => null]);
            ActivityLogger::log('tasks.restored', $task, "استعادة المهمة {$task->code()}");
            self::syncMilestone($task->project_milestone_id);
        });
    }

    /**
     * Subtask completion of a task, counting checklist items too.
     *
     * @return array{done: int, total: int, percent: int|null}
     */
    public static function progress(Task $task): array
    {
        $children = $task->relationLoaded('children') ? $task->children : $task->children()->get(['id', 'completed_at']);
        $done = $children->whereNotNull('completed_at')->count();
        $total = $children->count();
        $items = DB::table('checklist_items')->join('checklists', 'checklists.id', '=', 'checklist_items.checklist_id')
            ->where('checklists.task_id', $task->id)->selectRaw('count(*) as total, sum(case when done then 1 else 0 end) as done')->first();
        $total += (int) $items->total;
        $done += (int) $items->done;

        return ['done' => $done, 'total' => $total, 'percent' => $total ? (int) round($done / $total * 100) : null];
    }

    /** A milestone with tasks is done exactly when all its top-level tasks are complete. */
    public static function syncMilestone(?int $milestoneId): void
    {
        if (! $milestoneId || ! ($m = ProjectMilestone::find($milestoneId))) {
            return;
        }
        $total = $m->tasks()->count();
        if ($total === 0) {
            return;
        }
        $allDone = $m->tasks()->whereNull('completed_at')->doesntExist();
        if ($allDone && $m->status !== 'done') {
            $m->forceFill(['status' => 'done', 'done_at' => now(), 'done_by' => auth()->id()])->save();
            ActivityLogger::log('projects.milestone', $m->project, 'اكتملت مهام المرحلة: '.$m->title);
        } elseif (! $allDone && $m->status === 'done') {
            $m->forceFill(['status' => 'pending', 'done_at' => null, 'done_by' => null])->save();
        }
    }

    /** @return list<int> ids of all subtasks below the task */
    public static function descendantIds(Task $task, bool $withTrashed = false): array
    {
        $all = [];
        $level = [$task->id];
        for ($i = 0; $i < 5 && $level; $i++) {
            $level = Task::query()->when($withTrashed, fn ($q) => $q->withTrashed())->whereIn('parent_id', $level)->pluck('id')->all();
            $all = array_merge($all, $level);
        }

        return $all;
    }

    public static function nextPosition(int $listId, ?int $parentId): float
    {
        return (float) Task::withTrashed()->where('task_list_id', $listId)->where('parent_id', $parentId)->max('position') + 1024;
    }

    /** A status of the list's scheme: the given one, or the list's first status. */
    public static function statusFor(TaskList $list, mixed $statusId): TaskStatus
    {
        $q = TaskStatus::where('status_scheme_id', $list->status_scheme_id);
        $status = $statusId ? (clone $q)->find($statusId) : null;
        if ($statusId && ! $status) {
            // A status of another list (multi-list views): the same name here, else the same group.
            $other = TaskStatus::find($statusId);
            if (! $other) {
                throw ValidationException::withMessages(['status_id' => 'الحالة غير موجودة.']);
            }

            return self::matchStatus($list, $other);
        }

        return $status ?? $q->orderBy('sort')->orderBy('id')->firstOrFail();
    }

    /** Same name in the target list, else the first status of the same group, else the first status. */
    public static function matchStatus(TaskList $list, ?TaskStatus $from): TaskStatus
    {
        $statuses = TaskStatus::where('status_scheme_id', $list->status_scheme_id)->orderBy('sort')->orderBy('id')->get();

        return ($from ? $statuses->first(fn ($s) => $s->name === $from->name) ?? $statuses->firstWhere('group', $from->group) : null)
            ?? $statuses->firstOrFail();
    }

    /** Dates arrive as date + optional time: start_date / start_time, due_date / due_time. */
    private static function fillDates(Task $task, array $data): void
    {
        foreach (['start', 'due'] as $k) {
            if (! array_key_exists("{$k}_date", $data)) {
                continue;
            }
            $date = $data["{$k}_date"];
            $time = $data["{$k}_time"] ?? null;
            $task->{"{$k}_at"} = $date ? Carbon::parse($date.' '.($time ?: '00:00')) : null;
            $task->{"{$k}_has_time"} = (bool) ($date && $time);
        }
        if ($task->start_at && $task->due_at && $task->start_at->gt($task->due_at)) {
            throw ValidationException::withMessages(['due_date' => 'تاريخ التسليم قبل تاريخ البدء.']);
        }
    }

    private static function applyStatus(Task $task, TaskStatus $status): void
    {
        $task->status_id = $status->id;
        if ($status->isComplete()) {
            $task->completed_at ??= now();
        } else {
            $task->completed_at = null;
        }
    }

    /**
     * Readable summary of unsaved changes, for the activity history.
     *
     * @param  array<string, mixed>  $dirty
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private static function describeChanges(Task $task, array $dirty): ?array
    {
        $changes = [];
        $parts = [];
        foreach ($dirty as $field => $to) {
            if (in_array($field, ['description_text', 'completed_at', 'start_has_time', 'due_has_time', 'position', 'updated_at'], true)) {
                continue;
            }
            $from = $task->getOriginal($field);
            if ($field === 'status_id') {
                $names = TaskStatus::whereIn('id', [$from, $to])->pluck('name', 'id');
                $changes['status'] = ['from' => $names[$from] ?? null, 'to' => $names[$to] ?? null];
                $parts[] = 'الحالة ← «'.($names[$to] ?? '').'»';

                continue;
            }
            if ($field === 'priority') {
                $label = config("tasks.priorities.$to");
                $changes['priority'] = ['from' => $from, 'to' => $to];
                $parts[] = "الأولوية ← $label";

                continue;
            }
            if ($field === 'description') {
                $changes['description'] = true;
                $parts[] = 'تعديل الوصف';

                continue;
            }
            $fmt = fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d H:i') : $v;
            $changes[$field] = ['from' => $fmt($task->getOriginal($field)), 'to' => $fmt($task->getAttribute($field))];
            $label = self::FIELD_LABELS[$field] ?? $field;
            $parts[] = match ($field) {
                'title' => "العنوان ← «{$task->title}»",
                'due_at', 'start_at' => $label.' ← '.($task->$field ? $task->$field->format($task->{str_replace('_at', '_has_time', $field)} ? 'Y-m-d H:i' : 'Y-m-d') : 'بلا تاريخ'),
                'time_estimate_min' => $label.' ← '.($task->time_estimate_min ? round($task->time_estimate_min / 60, 2).' ساعة' : '—'),
                default => "تعديل $label",
            };
        }
        return $parts ? [implode(' · ', $parts), $changes] : null;
    }

    /** Keep only real, active users. @param list<mixed> $ids @return list<int> */
    private static function activeUsers(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($ids))));

        return $ids ? User::whereIn('id', $ids)->where('is_active', true)->pluck('id')->all() : [];
    }

    private static function subtreeHeight(Task $task): int
    {
        $h = 0;
        $level = [$task->id];
        while ($h < 5 && ($level = Task::whereIn('parent_id', $level)->pluck('id')->all())) {
            $h++;
        }

        return $h;
    }

    private static function refreshDepths(Task $task): void
    {
        $level = [$task->id];
        $depth = $task->depth;
        for ($i = 0; $i < 5 && $level; $i++) {
            $depth++;
            Task::whereIn('parent_id', $level)->update(['depth' => $depth, 'task_list_id' => $task->task_list_id, 'project_id' => $task->project_id]);
            $level = Task::whereIn('parent_id', $level)->pluck('id')->all();
        }
    }
}
