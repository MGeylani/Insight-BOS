<?php

namespace App\Http\Controllers\Crm;

use App\Models\CrmActivity;
use App\Services\ActivityLogger;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ActivityController extends CrmController
{
    /** Follow-up list: overdue, today, upcoming and recently done. */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $mine = $request->query('who', 'mine') === 'mine';

        $base = CrmActivity::visibleTo($user)->with(['subject', 'user:id,name', 'creator:id,name'])
            ->when($mine, fn ($q) => $q->where(fn ($q) => $q->where('user_id', $user->id)->orWhere(fn ($q) => $q->whereNull('user_id')->where('created_by', $user->id))));

        $map = fn ($rows) => $rows->map(fn (CrmActivity $a) => [...$this->activityPayload($a), 'subject' => $this->subjectLink($a)]);

        return Inertia::render('crm/Activities', [
            'overdue' => $map((clone $base)->pending()->where('due_at', '<', now()->startOfDay())->orderBy('due_at')->limit(200)->get()),
            'today' => $map((clone $base)->pending()->whereBetween('due_at', [now()->startOfDay(), now()->endOfDay()])->orderBy('due_at')->get()),
            'upcoming' => $map((clone $base)->pending()->where(fn ($q) => $q->where('due_at', '>', now()->endOfDay())->orWhereNull('due_at'))->orderByRaw('due_at IS NULL')->orderBy('due_at')->limit(200)->get()),
            'done' => $map((clone $base)->whereNotNull('completed_at')->latest('completed_at')->limit(30)->get()),
            'who' => $mine ? 'mine' : 'all',
            ...$this->options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'subject_type' => ['required', Rule::in(['lead', 'client', 'opportunity'])],
            'subject_id' => ['required', 'integer'],
            ...$this->rules(),
        ]);
        $subject = Relation::getMorphedModel($data['subject_type'])::findOrFail($data['subject_id']);
        $this->authorizeRecord($subject, 'crm.view');

        $activity = CrmActivity::create($data + ['created_by' => $request->user()->id, 'user_id' => $data['user_id'] ?? $request->user()->id]);
        if ($request->boolean('completed')) {
            $activity->forceFill(['completed_at' => now()])->save();
        }
        ActivityLogger::log('crm.activity_created', $subject, "نشاط: {$activity->title}");

        return back()->with('success', 'تم تسجيل النشاط.');
    }

    public function update(Request $request, CrmActivity $activity): RedirectResponse
    {
        $this->authorizeRecord($activity, 'crm.view');
        $data = $request->validate($this->rules());
        $activity->fill($data);
        if ($activity->isDirty('remind_at')) {
            $activity->reminded_at = null; // a new reminder time is sent again
        }
        $activity->save();

        return back()->with('success', 'تم حفظ النشاط.');
    }

    public function complete(Request $request, CrmActivity $activity): RedirectResponse
    {
        $this->authorizeRecord($activity, 'crm.view');
        $activity->forceFill(['completed_at' => $activity->completed_at ? null : now()])->save();

        return back();
    }

    public function destroy(CrmActivity $activity): RedirectResponse
    {
        $this->authorizeRecord($activity, 'crm.edit');
        $activity->delete();

        return back()->with('success', 'تم حذف النشاط.');
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'type' => ['required', Rule::in(array_keys(config('crm.activity_types')))],
            'title' => ['required', 'string', 'max:190'],
            'body' => ['nullable', 'string', 'max:5000'],
            'due_at' => ['nullable', 'date'],
            'remind_at' => ['nullable', 'date'],
            'user_id' => ['nullable', 'exists:users,id'],
        ];
    }

    /** @return array{type: string, name: string, url: string}|null */
    private function subjectLink(CrmActivity $a): ?array
    {
        $s = $a->subject;
        if (! $s) {
            return null;
        }

        return match ($a->subject_type) {
            'lead' => ['type' => 'lead', 'name' => $s->displayName(), 'url' => "/crm/leads/{$s->id}"],
            'client' => ['type' => 'client', 'name' => $s->name, 'url' => "/crm/clients/{$s->id}"],
            'opportunity' => ['type' => 'opportunity', 'name' => $s->title, 'url' => "/crm/opportunities/{$s->id}"],
            default => null,
        };
    }
}
