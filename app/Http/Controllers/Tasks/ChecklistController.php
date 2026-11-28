<?php

namespace App\Http\Controllers\Tasks;

use App\Http\Controllers\Controller;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Task;
use App\Services\Tasks\Checklists;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Checklists follow the task: anyone who may edit the task may edit its checklists. */
class ChecklistController extends Controller
{
    public function store(Request $request, Task $task): RedirectResponse
    {
        $this->authorizeRecord($task, 'tasks.edit');
        Checklists::create($task, $request->validate(['name' => ['required', 'string', 'max:160']])['name']);

        return back();
    }

    public function update(Request $request, Checklist $checklist): RedirectResponse
    {
        $this->authorizeRecord($checklist->task, 'tasks.edit');
        Checklists::rename($checklist, $request->validate(['name' => ['required', 'string', 'max:160']])['name']);

        return back();
    }

    public function destroy(Checklist $checklist): RedirectResponse
    {
        $this->authorizeRecord($checklist->task, 'tasks.edit');
        Checklists::delete($checklist);

        return back();
    }

    public function storeItem(Request $request, Checklist $checklist): RedirectResponse
    {
        $this->authorizeRecord($checklist->task, 'tasks.edit');
        Checklists::addItem($checklist, $request->validate([
            'text' => ['required', 'string', 'max:500'],
            'parent_id' => ['nullable', 'integer'],
            'assignee_id' => ['nullable', 'integer', 'exists:users,id'],
        ]));

        return back();
    }

    public function updateItem(Request $request, ChecklistItem $item): RedirectResponse
    {
        $this->authorizeRecord($item->checklist->task, 'tasks.edit');
        Checklists::updateItem($item, $request->validate([
            'text' => ['sometimes', 'required', 'string', 'max:500'],
            'assignee_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'done' => ['sometimes', 'boolean'],
        ]), $request->user()->id);

        return back();
    }

    public function destroyItem(ChecklistItem $item): RedirectResponse
    {
        $this->authorizeRecord($item->checklist->task, 'tasks.edit');
        Checklists::deleteItem($item);

        return back();
    }
}
