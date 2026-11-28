<?php

namespace App\Http\Controllers\Tasks;

use App\Http\Controllers\Controller;
use App\Models\SavedView;
use App\Services\ActivityLogger;
use App\Services\Tasks\TaskQuery;
use App\Services\Tasks\ViewConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Personal or shared task views (type + filters + grouping + sorting + columns). */
class SavedViewController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $view = SavedView::create([
            'owner_id' => $request->user()->id,
            'name' => $data['name'],
            'type' => $data['type'],
            'location_type' => $data['location_type'] ?? null,
            'location_id' => ! empty($data['location_type']) ? ($data['location_id'] ?? null) : null,
            'is_shared' => (bool) ($data['is_shared'] ?? false),
            'config' => $this->config($request),
        ]);
        ActivityLogger::log('tasks.view_saved', null, "حفظ عرض المهام «{$view->name}»".($view->is_shared ? ' (مشترك)' : ''));

        return redirect($this->url($view))->with('success', 'تم حفظ العرض.');
    }

    /** Rename, share, or overwrite the settings with the current ones. */
    public function update(Request $request, SavedView $view): RedirectResponse
    {
        abort_unless($view->canManage($request->user()), 403);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:80'],
            'is_shared' => ['sometimes', 'boolean'],
            'replace_config' => ['sometimes', 'boolean'],
            'type' => ['sometimes', Rule::in(SavedView::TYPES)],
        ]);
        $view->fill(array_intersect_key($data, array_flip(['name', 'is_shared', 'type'])));
        if ($request->boolean('replace_config')) {
            $view->config = $this->config($request);
        }
        $view->save();

        return redirect($this->url($view))->with('success', 'تم تحديث العرض.');
    }

    public function destroy(Request $request, SavedView $view): RedirectResponse
    {
        abort_unless($view->canManage($request->user()), 403);
        $view->delete();
        $loc = $view->location_type ? "?{$view->location_type}={$view->location_id}" : '';

        return redirect("/tasks$loc")->with('success', 'حُذف العرض.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'type' => ['required', Rule::in(SavedView::TYPES)],
            'location_type' => ['nullable', Rule::in(['client', 'project', 'list'])],
            'location_id' => ['nullable', 'integer'],
            'is_shared' => ['nullable', 'boolean'],
        ]);
    }

    /** @return array<string, mixed> */
    private function config(Request $request): array
    {
        $v = ViewConfig::clean(['view' => $request->input('type')] + (array) $request->input('config', []));
        unset($v['view']);

        return $v + ['filters' => TaskQuery::clean((array) $request->input('config.filters', []))];
    }

    private function url(SavedView $view): string
    {
        $loc = $view->location_type ? "{$view->location_type}={$view->location_id}&" : '';

        return "/tasks?{$loc}saved={$view->id}";
    }
}
