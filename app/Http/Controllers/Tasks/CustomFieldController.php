<?php

namespace App\Http\Controllers\Tasks;

use App\Http\Controllers\Controller;
use App\Models\CustomField;
use App\Models\Task;
use App\Services\ActivityLogger;
use App\Services\Tasks\CustomFields;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Custom field definitions (per scope) and their values on tasks. */
class CustomFieldController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, true);
        abort_unless(CustomFields::canManage($request->user(), $data['scope_type'], $data['scope_id'] ?? null), 403);
        $f = CustomFields::save(null, $data, $request->user());

        return back()->with('success', "تمت إضافة الحقل «{$f->name}».");
    }

    public function update(Request $request, CustomField $field): RedirectResponse
    {
        $user = $request->user();
        abort_unless(CustomFields::canManage($user, $field->scope_type, $field->scope_id), 403);
        $data = $this->validated($request, false);
        if (isset($data['scope_type'])) {
            abort_unless(CustomFields::canManage($user, $data['scope_type'], $data['scope_id'] ?? null), 403);
        }
        CustomFields::save($field, $data, $user);

        return back()->with('success', 'تم حفظ الحقل.');
    }

    public function destroy(Request $request, CustomField $field): RedirectResponse
    {
        abort_unless(CustomFields::canManage($request->user(), $field->scope_type, $field->scope_id), 403);
        $field->delete();
        ActivityLogger::log('tasks.field_deleted', null, "حذف الحقل المخصص «{$field->name}»");

        return back()->with('success', 'حُذف الحقل (قيمه محفوظة ولا تظهر).');
    }

    public function setValue(Request $request, Task $task, CustomField $field): RedirectResponse
    {
        $this->authorizeRecord($task, 'tasks.edit');
        CustomFields::setValue($task, $field, $request->input('value'));

        return back();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $data = $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:80'],
            'type' => [$creating ? 'required' : 'prohibited', Rule::in(CustomField::TYPES)],
            'options' => ['nullable', 'array'],
            'scope_type' => [$creating ? 'required' : 'sometimes', Rule::in(['all', 'client', 'project', 'list'])],
            'scope_id' => ['nullable', 'integer'],
            'type_ids' => ['sometimes', 'array'],
            'type_ids.*' => ['integer'],
        ]);
        $scope = $data['scope_type'] ?? null;
        if ($scope && $scope !== 'all') {
            $table = ['client' => 'clients', 'project' => 'projects', 'list' => 'task_lists'][$scope];
            $request->validate(['scope_id' => ['required', 'integer', Rule::exists($table, 'id')]]);
        }
        if (! $creating && ! isset($data['name'])) {
            $data['name'] = $request->route('field')->name;
        }

        return $data;
    }
}
