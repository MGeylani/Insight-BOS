<?php

namespace App\Http\Controllers\Tasks;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\TaskType;
use App\Models\CustomField;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Task types (settings.manage): name, icon, colour, milestone flag and the fields that belong to the type. */
class TaskTypeController extends Controller
{
    public const ICONS = [
        'circle-check', 'diamond', 'package', 'camera', 'megaphone', 'users', 'pen-tool', 'video', 'file-text', 'calendar',
        'bug', 'lightbulb', 'phone', 'printer', 'clapperboard', 'image', 'mail', 'flag', 'star', 'rocket',
    ];

    public function store(Request $request): RedirectResponse
    {
        $type = TaskType::create($this->validated($request) + ['sort' => (int) TaskType::max('sort') + 1]);
        $this->sync($request, $type);

        return back()->with('success', "تمت إضافة النوع «{$type->name}».");
    }

    public function update(Request $request, TaskType $type): RedirectResponse
    {
        $type->update($this->validated($request));
        $this->sync($request, $type);

        return back()->with('success', 'تم حفظ النوع.');
    }

    public function destroy(TaskType $type): RedirectResponse
    {
        if ($type->is_default) {
            return back()->with('error', 'لا يُحذف النوع الافتراضي.');
        }
        $default = TaskType::where('is_default', true)->value('id');
        Task::withTrashed()->where('task_type_id', $type->id)->update(['task_type_id' => $default]);
        $type->delete();

        return back()->with('success', 'حُذف النوع ونُقلت مهامه إلى النوع الافتراضي.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'icon' => ['required', Rule::in(self::ICONS)],
            'color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'is_milestone' => ['boolean'],
            'field_ids' => ['nullable', 'array'],
            'field_ids.*' => ['integer'],
        ]);
        unset($data['field_ids']);

        return $data;
    }

    private function sync(Request $request, TaskType $type): void
    {
        if ($request->has('field_ids')) {
            $type->fields()->sync(CustomField::whereIn('id', (array) $request->input('field_ids'))->pluck('id'));
        }
        if ($request->boolean('is_default')) {
            TaskType::whereKeyNot($type->id)->update(['is_default' => false]);
            $type->update(['is_default' => true]);
        }
    }
}
