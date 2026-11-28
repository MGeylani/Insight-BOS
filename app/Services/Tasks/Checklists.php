<?php

namespace App\Services\Tasks;

use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Task;
use App\Services\ActivityLogger;
use Illuminate\Validation\ValidationException;

/** Checklists of a task; items nest up to three levels and count in the task's progress. */
class Checklists
{
    public static function create(Task $task, string $name): Checklist
    {
        $c = $task->checklists()->create(['name' => trim($name), 'sort' => (int) $task->checklists()->max('sort') + 1]);
        ActivityLogger::log('tasks.checklist', $task, "إضافة قائمة تحقق «{$c->name}»");

        return $c;
    }

    public static function rename(Checklist $c, string $name): void
    {
        $c->update(['name' => trim($name)]);
    }

    public static function delete(Checklist $c): void
    {
        $c->delete();
        ActivityLogger::log('tasks.checklist', $c->task, "حذف قائمة التحقق «{$c->name}»");
    }

    /** @param array{text: string, parent_id?: int|null, assignee_id?: int|null} $data */
    public static function addItem(Checklist $c, array $data): ChecklistItem
    {
        $parent = ! empty($data['parent_id']) ? $c->items()->whereKey($data['parent_id'])->first() : null;
        if (! empty($data['parent_id']) && ! $parent) {
            throw ValidationException::withMessages(['parent_id' => 'البند الأب لا يتبع هذه القائمة.']);
        }
        if ($parent && $parent->depth() >= (int) config('tasks.max_depth')) {
            throw ValidationException::withMessages(['parent_id' => 'بنود قائمة التحقق تصل إلى ثلاثة مستويات فقط.']);
        }

        return $c->items()->create([
            'text' => trim($data['text']),
            'parent_id' => $parent?->id,
            'assignee_id' => $data['assignee_id'] ?? null,
            'sort' => (int) $c->items()->where('parent_id', $parent?->id)->max('sort') + 1,
        ]);
    }

    /** @param array<string, mixed> $data */
    public static function updateItem(ChecklistItem $item, array $data, int $userId): ChecklistItem
    {
        $item->fill(array_intersect_key($data, array_flip(['text', 'assignee_id'])));
        if (array_key_exists('done', $data) && (bool) $data['done'] !== $item->done) {
            $item->forceFill($data['done']
                ? ['done' => true, 'done_at' => now(), 'done_by' => $userId]
                : ['done' => false, 'done_at' => null, 'done_by' => null]);
            // Checking a parent checks its children too.
            if ($data['done']) {
                self::checkChildren($item, $userId);
            }
        }
        $item->save();

        return $item;
    }

    public static function deleteItem(ChecklistItem $item): void
    {
        $item->delete();
    }

    private static function checkChildren(ChecklistItem $item, int $userId): void
    {
        foreach (ChecklistItem::where('parent_id', $item->id)->where('done', false)->get() as $child) {
            $child->forceFill(['done' => true, 'done_at' => now(), 'done_by' => $userId])->save();
            self::checkChildren($child, $userId);
        }
    }
}
