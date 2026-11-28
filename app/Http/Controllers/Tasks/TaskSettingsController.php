<?php

namespace App\Http\Controllers\Tasks;

use App\Http\Controllers\Controller;
use App\Models\StatusScheme;
use App\Models\Tag;
use App\Services\ActivityLogger;
use App\Services\Tasks\TaskLists;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Company-wide task settings: tags and the status templates new lists start from. */
class TaskSettingsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('settings/Tasks', [
            'tags' => Tag::withCount('tasks')->orderBy('name')->get(),
            'templates' => StatusScheme::where('is_template', true)->with('statuses')->orderByDesc('is_default')->orderBy('id')->get()
                ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'is_default' => $s->is_default, 'statuses' => $s->statuses->map->toUi()->values()]),
            'groups' => config('tasks.status_groups'),
            'internal' => TaskLists::internalClient()->only('id', 'name', 'task_prefix'),
            'types' => \App\Models\TaskType::with('fields:id')->orderBy('sort')->orderBy('id')->get()->map->toUi()->values(),
            'icons' => TaskTypeController::ICONS,
            'fields' => \App\Models\CustomField::with('types:id')->orderBy('sort')->orderBy('id')->get()
                ->map(fn ($f) => [...$f->toUi(), 'scope_label' => self::scopeLabel($f)])->values(),
            'fieldTypes' => \App\Services\Tasks\CustomFields::TYPE_LABELS,
            'currencies' => \App\Models\Currency::where('is_active', true)->pluck('code'),
        ]);
    }

    private static function scopeLabel(\App\Models\CustomField $f): string
    {
        return match ($f->scope_type) {
            'client' => 'العميل: '.(\App\Models\Client::withTrashed()->whereKey($f->scope_id)->value('name') ?? '—'),
            'project' => 'المشروع: '.(\App\Models\Project::withTrashed()->whereKey($f->scope_id)->value('name') ?? '—'),
            'list' => 'القائمة: '.(\App\Models\TaskList::withTrashed()->whereKey($f->scope_id)->value('name') ?? '—'),
            default => 'كل المهام',
        };
    }

    /** Anyone who edits tasks may add a tag from the tag picker. */
    public function storeTag(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:48', 'unique:tags,name'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);
        $tag = Tag::create($data + ['color' => '#0082F6']);
        ActivityLogger::log('tasks.tag_created', $tag, "إضافة الوسم «{$tag->name}»");

        return back()->with('success', "تمت إضافة الوسم «{$tag->name}».");
    }

    public function updateTag(Request $request, Tag $tag): RedirectResponse
    {
        $tag->update($request->validate([
            'name' => ['required', 'string', 'max:48', Rule::unique('tags', 'name')->ignore($tag->id)],
            'color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]));

        return back()->with('success', 'تم حفظ الوسم.');
    }

    public function destroyTag(Tag $tag): RedirectResponse
    {
        $tag->delete();
        ActivityLogger::log('tasks.tag_deleted', null, "حذف الوسم «{$tag->name}»");

        return back()->with('success', 'تم حذف الوسم.');
    }

    public function updateTemplate(Request $request, StatusScheme $scheme): RedirectResponse
    {
        abort_unless($scheme->is_template, 404);
        $data = TaskListController::validatedStatuses($request);
        TaskLists::saveStatuses($scheme, $data['statuses']);
        if ($request->boolean('is_default')) {
            StatusScheme::where('is_template', true)->update(['is_default' => false]);
            $scheme->update(['is_default' => true]);
        }

        return back()->with('success', 'تم حفظ القالب. القوائم الجديدة تبدأ منه؛ القوائم الحالية لا تتغير.');
    }

    public function updatePrefix(Request $request): RedirectResponse
    {
        $client = TaskLists::internalClient();
        $data = $request->validate(['task_prefix' => ['required', 'string', 'max:6', 'regex:/^[A-Za-z][A-Za-z0-9]*$/', Rule::unique('clients', 'task_prefix')->ignore($client->id)]]);
        $client->forceFill(['task_prefix' => strtoupper($data['task_prefix'])])->save();

        return back()->with('success', 'تم حفظ البادئة.');
    }
}
