<?php

namespace App\Http\Controllers\Tasks;

use App\Http\Controllers\Controller;
use App\Models\IntakeForm;
use App\Models\TaskList;
use App\Services\ActivityLogger;
use App\Services\Storage\Attachments;
use App\Services\Tasks\CustomFields;
use App\Services\Tasks\IntakeForms;
use App\Services\Tasks\TaskPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Intake forms: managed by whoever manages the target list; filled by anyone signed in, or by anyone when public. */
class IntakeFormController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $forms = IntakeForm::with(['list.client:id,name', 'creator:id,name'])->whereIn('task_list_id', TaskList::visibleTo($user)->select('task_lists.id'))->latest('id')->get();

        return Inertia::render('tasks/Forms', [
            'forms' => $forms->map(fn (IntakeForm $f) => [
                ...$f->only('id', 'name', 'description', 'task_list_id', 'is_public', 'is_active', 'fields', 'defaults', 'confirmation', 'submissions_count'),
                'list' => $f->list?->name.' · '.$f->list?->client?->name,
                'url' => $f->url(),
                'last_submitted_at' => $f->last_submitted_at?->toIso8601String(),
                'can_manage' => $this->canManage($request, $f->list),
            ])->values(),
            'lists' => TaskList::visibleTo($user)->with('client:id,name')->orderBy('client_id')->orderBy('sort')->limit(500)->get()
                ->filter(fn (TaskList $l) => CustomFields::canManage($user, 'list', $l->id))
                ->map(fn ($l) => ['id' => $l->id, 'name' => $l->name.' · '.$l->client?->name])->values(),
            'standard' => IntakeForms::STANDARD,
            'storage' => Attachments::policy()['connected'],
            ...TaskPresenter::vocab(),
        ]);
    }

    /** Custom fields of a list that a form can ask for. */
    public function fields(Request $request): JsonResponse
    {
        $list = TaskList::findOrFail((int) $request->query('list'));
        abort_unless($list->isVisibleTo($request->user()), 403);

        return response()->json(IntakeForms::customFields($list)->map->toUi()->values());
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $list = TaskList::findOrFail($data['task_list_id']);
        abort_unless($this->canManage($request, $list), 403);
        IntakeForms::create($list, $data, $request->user());

        return back()->with('success', 'حُفظ النموذج. انسخ رابطه وشاركه.');
    }

    public function update(Request $request, IntakeForm $form): RedirectResponse
    {
        abort_unless($this->canManage($request, $form->list), 403);
        if ($request->has('is_active') && ! $request->has('name')) {
            $form->update(['is_active' => $request->boolean('is_active')]);

            return back();
        }
        $data = $this->validated($request);
        $list = TaskList::findOrFail($data['task_list_id']);
        abort_unless($this->canManage($request, $list), 403);
        $form->update([
            ...collect($data)->only('name', 'description', 'task_list_id', 'is_public', 'confirmation')->all(),
            ...IntakeForms::clean($data, $list),
        ]);
        ActivityLogger::log('tasks.form_saved', $form, "تعديل نموذج الاستقبال «{$form->name}»");

        return back()->with('success', 'حُفظ النموذج.');
    }

    public function destroy(Request $request, IntakeForm $form): RedirectResponse
    {
        abort_unless($this->canManage($request, $form->list), 403);
        $form->delete();

        return back()->with('success', 'حُذف النموذج (المهام التي أنشأها تبقى).');
    }

    /** The form itself, at /forms/{token}. */
    public function show(Request $request, string $token): Response|RedirectResponse
    {
        $form = IntakeForm::with('list')->where('token', $token)->firstOrFail();
        if (! $form->is_public && ! $request->user()) {
            return redirect()->guest(route('login'));
        }
        $cf = IntakeForms::customFields($form->list)->keyBy('id');

        return Inertia::render('forms/Fill', [
            'form' => [
                ...$form->only('name', 'description', 'token', 'is_active', 'confirmation'),
                'fields' => collect($form->fields)->map(fn ($f) => $f + ['field' => str_starts_with($f['key'], 'cf_') ? $cf->get((int) substr($f['key'], 3))?->toUi() : null])
                    ->filter(fn ($f) => ! str_starts_with($f['key'], 'cf_') || $f['field'])->values(),
            ],
            'priorities' => config('tasks.priorities'),
            'signedIn' => (bool) $request->user(),
            'sent' => $request->session()->get('form_sent'),
        ]);
    }

    public function submit(Request $request, string $token): RedirectResponse
    {
        $form = IntakeForm::where('token', $token)->firstOrFail();
        $user = $request->user();
        abort_unless($form->is_public || $user, 403);
        if (filled($request->input('website'))) { // honeypot: bots fill every field
            return back()->with('form_sent', ['code' => null]);
        }
        $request->validate(['answers' => ['nullable', 'array'], 'files' => ['nullable', 'array', 'max:5'], 'files.*' => ['array', 'max:10'], 'files.*.*' => ['file']],
            ['files.*.*.file' => 'تعذّر استلام الملف؛ ربما تجاوز حد الرفع في الخادم.']);
        $task = IntakeForms::submit($form, (array) $request->input('answers', []), (array) $request->file('files', []), $user);
        $visible = $user && $task->isVisibleTo($user);

        return redirect("/forms/{$token}")->with('form_sent', ['code' => $task->code(), 'url' => $visible ? "/tasks/{$task->id}" : null]);
    }

    private function canManage(Request $request, ?TaskList $list): bool
    {
        return $list !== null && CustomFields::canManage($request->user(), 'list', $list->id);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'task_list_id' => ['required', 'integer', 'exists:task_lists,id'],
            'is_public' => ['boolean'],
            'confirmation' => ['nullable', 'string', 'max:500'],
            'fields' => ['required', 'array', 'max:30'],
            'defaults' => ['nullable', 'array'],
        ]);
    }
}
