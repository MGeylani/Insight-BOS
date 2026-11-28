<?php

namespace App\Http\Controllers\Tasks;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\CrmActivity;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\TaskList;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Tasks\TaskPresenter;
use App\Services\Tasks\TimeTracking;
use App\Support\RichText;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/** "My Work": what needs me today, my calendar and my time. */
class MyWorkController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $mine = fn () => Task::query()->whereIn('tasks.id', fn ($q) => $q->select('task_id')->from('task_assignees')->where('user_id', $user->id))->open();
        $rows = fn ($q, $limit = 100) => TaskPresenter::rows($q->orderByRaw('tasks.due_at is null')->orderBy('tasks.due_at'), $limit);

        $sections = [
            'overdue' => $rows($mine()->overdue()),
            'today' => $rows($mine()->whereDate('tasks.due_at', today())->where(fn ($q) => $q->where('tasks.due_has_time', false)->orWhere('tasks.due_at', '>=', now()))),
            'upcoming' => $rows($mine()->whereDate('tasks.due_at', '>', today())->whereDate('tasks.due_at', '<=', today()->addDays(7))),
            'later' => $rows($mine()->whereDate('tasks.due_at', '>', today()->addDays(7)), 50),
            'noDate' => $rows($mine()->whereNull('tasks.due_at'), 50),
        ];
        $listIds = collect($sections)->flatten(1)->pluck('list_id')->unique()->values();

        return Inertia::render('tasks/MyWork', [
            ...$sections,
            // Each row offers the statuses of its own list.
            'listStatuses' => TaskList::whereIn('id', $listIds)->with('statuses')->get()
                ->mapWithKeys(fn (TaskList $l) => [$l->id => $l->statuses->map->toUi()->values()]),
            'comments' => Comment::with(['user:id,name', 'commentable'])->where('commentable_type', 'task')
                ->where('assigned_to', $user->id)->whereNull('resolved_at')->latest('id')->limit(50)->get()
                ->filter(fn (Comment $c) => $c->commentable && ! $c->commentable->trashed())
                ->map(fn (Comment $c) => [
                    'id' => $c->id, 'excerpt' => RichText::excerpt($c->body, 160), 'user' => $c->user?->name,
                    'created_at' => $c->created_at?->toIso8601String(),
                    'task' => ['id' => $c->commentable->id, 'code' => $c->commentable->code(), 'title' => $c->commentable->title],
                ])->values(),
            'reminders' => Reminder::with('task:id,title,seq,client_id')->where('user_id', $user->id)->whereNull('done_at')
                ->orderBy('remind_at')->limit(50)->get()
                ->map(fn (Reminder $r) => [
                    'id' => $r->id, 'note' => $r->note, 'remind_at' => $r->remind_at->format('Y-m-d\TH:i'), 'sent' => $r->sent_at !== null,
                    'task' => $r->task ? ['id' => $r->task->id, 'code' => $r->task->code(), 'title' => $r->task->title] : null,
                ]),
            'week' => TimeTracking::totals(TimeEntry::where('user_id', $user->id)->whereBetween('started_at', [today()->startOfWeek(Carbon::SATURDAY), today()->endOfWeek(Carbon::FRIDAY)])),
            'appointments' => CrmActivity::where('user_id', $user->id)->whereNull('completed_at')->whereDate('due_at', today())->orderBy('due_at')->get()
                ->map(fn ($a) => ['id' => $a->id, 'title' => $a->title, 'type' => $a->type, 'due_at' => $a->due_at?->format('Y-m-d\TH:i')]),
            'statuses' => [],
            ...TaskPresenter::vocab(),
            'can' => [
                'edit' => $user->hasPermission('tasks.edit'), 'assign' => $user->hasPermission('tasks.assign'),
                'create' => false, 'track' => $user->hasPermission('tasks.track_time'),
            ],
        ]);
    }

    /** Day / week / month of my tasks and my CRM appointments. */
    public function calendar(Request $request): Response
    {
        $user = $request->user();
        $from = today()->subDays(60);
        $to = today()->addDays(180);
        $tasks = Task::query()->whereIn('tasks.id', fn ($q) => $q->select('task_id')->from('task_assignees')->where('user_id', $user->id))
            ->where(fn ($q) => $q->whereBetween('tasks.due_at', [$from, $to])->orWhereBetween('tasks.start_at', [$from, $to]));

        return Inertia::render('tasks/MyCalendar', [
            'tasks' => TaskPresenter::rows($tasks, 2000),
            'appointments' => $user->hasPermission('crm.view')
                ? CrmActivity::where(fn ($q) => $q->where('user_id', $user->id)->orWhere(fn ($w) => $w->whereNull('user_id')->where('created_by', $user->id)))
                    ->whereNotNull('due_at')->whereBetween('due_at', [$from, $to])->get()
                    ->map(fn (CrmActivity $a) => [
                        'id' => 'crm-'.$a->id, 'title' => '📅 '.$a->title, 'start' => $a->due_at->format('Y-m-d\TH:i'), 'allDay' => false,
                        'url' => match ($a->subject_type) {
                            'lead' => "/crm/leads/{$a->subject_id}", 'client' => "/crm/clients/{$a->subject_id}",
                            'opportunity' => "/crm/opportunities/{$a->subject_id}", default => '/crm/activities',
                        },
                        'backgroundColor' => $a->completed_at ? '#cbd5e1' : '#481C4E', 'borderColor' => '#481C4E', 'textColor' => '#fff', 'editable' => false,
                    ])->values()
                : [],
            'can' => ['edit' => $user->hasPermission('tasks.edit'), 'create' => false],
        ]);
    }

    /** My week of time entries (or someone else's with tasks.track_time = all). */
    public function time(Request $request): Response
    {
        $user = $request->user();
        $who = $user;
        if ($request->integer('user') && $request->integer('user') !== $user->id) {
            abort_unless($user->permissionScope('tasks.track_time') === 'all', 403);
            $who = User::findOrFail($request->integer('user'));
        }
        $start = ($request->date('week') ?? today())->copy()->startOfWeek(Carbon::SATURDAY);
        $end = $start->copy()->addDays(6)->endOfDay();
        $q = TimeEntry::where('user_id', $who->id)->whereBetween('started_at', [$start, $end]);
        $seeCost = $user->hasPermission('hr.view_salaries') || $user->hasPermission('finance.view');

        return Inertia::render('tasks/MyTime', [
            'who' => $who->only('id', 'name'),
            'timesheet' => \App\Services\Tasks\Timesheets::payload(\App\Services\Tasks\Timesheets::for($who->id, $start)),
            'canApprove' => \App\Services\Tasks\Timesheets::canApprove($user, $who),
            'week' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'entries' => (clone $q)->with(['task:id,title,seq,client_id,project_id', 'task.client:id,task_prefix', 'project:id,name'])->orderBy('started_at')->get()
                ->map(fn (TimeEntry $e) => [
                    'id' => $e->id, 'date' => $e->started_at->toDateString(), 'from' => $e->started_at->format('H:i'),
                    'to' => $e->ended_at?->format('H:i'), 'minutes' => $e->liveMinutes(), 'running' => $e->ended_at === null,
                    'billable' => $e->billable, 'note' => $e->note, 'source' => $e->source,
                    'task' => $e->task ? ['id' => $e->task->id, 'code' => $e->task->code(), 'title' => $e->task->title] : null,
                    'project' => $e->project?->name,
                    'cost' => $seeCost ? $e->cost() : null,
                ]),
            'totals' => TimeTracking::totals($q),
            'seeCost' => $seeCost,
            'people' => $user->permissionScope('tasks.track_time') === 'all' ? User::where('is_active', true)->orderBy('name')->get(['id', 'name']) : [],
            'myTasks' => Task::query()->whereIn('tasks.id', fn ($w) => $w->select('task_id')->from('task_assignees')->where('user_id', $who->id))->open()
                ->with('client:id,task_prefix')->latest('updated_at')->limit(100)->get()->map(fn ($t) => ['id' => $t->id, 'label' => $t->code().' · '.$t->title]),
        ]);
    }
}
