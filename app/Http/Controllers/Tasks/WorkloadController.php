<?php

namespace App\Http\Controllers\Tasks;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeOff;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Tasks\Timesheets;
use App\Services\Tasks\Workload;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Workload (hours vs capacity) and the team timeline (who works on what). */
class WorkloadController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $start = Timesheets::weekStart($request->date('week') ?? today());
        $weeks = $request->integer('weeks') === 2 ? 2 : 1;
        $end = $start->copy()->addDays(7 * $weeks - 1)->endOfDay();
        $projectId = $request->integer('project') ?: null;
        $people = $this->people($user);

        return Inertia::render('tasks/Workload', [
            'mode' => $request->input('mode') === 'timeline' ? 'timeline' : 'workload',
            'week' => $start->toDateString(), 'weeks' => $weeks, 'project' => $projectId,
            'projects' => Project::visibleTo($user)->whereIn('status', ['planning', 'active', 'on_hold'])->orderBy('name')->get(['id', 'name']),
            ...Workload::build($people, $start, $end, $projectId),
            'timeOff' => TimeOff::with('user:id,name')->whereIn('user_id', $people->pluck('id'))
                ->whereDate('ends_on', '>=', $start->toDateString())->whereDate('starts_on', '<=', $end->toDateString())->orderBy('starts_on')->get()
                ->map(fn ($o) => [...$o->only('id', 'user_id', 'type', 'note'), 'user' => $o->user?->name, 'starts_on' => $o->starts_on->toDateString(), 'ends_on' => $o->ends_on->toDateString()]),
            'canManage' => $this->canManage($user),
            'me' => $user->id,
        ]);
    }

    /** Hours a day and working days of a person (managers). */
    public function capacity(Request $request, User $person): RedirectResponse
    {
        abort_unless($this->canManage($request->user()), 403);
        $data = $request->validate([
            'capacity_hours' => ['required', 'numeric', 'min:0', 'max:24'],
            'work_days' => ['required', 'array'],
            'work_days.*' => ['integer', 'between:0,6'],
        ]);
        $person->forceFill(['capacity_hours' => $data['capacity_hours'], 'work_days' => array_values(array_unique(array_map('intval', $data['work_days'])))])->save();
        ActivityLogger::log('users.capacity', $person, "سعة {$person->name}: {$data['capacity_hours']} ساعة يومياً");

        return back()->with('success', 'تم حفظ السعة.');
    }

    public function storeTimeOff(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'type' => ['required', Rule::in(['vacation', 'sick', 'other'])],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        abort_unless((int) $data['user_id'] === $user->id || $this->canManage($user), 403);
        $off = TimeOff::create($data + ['created_by' => $user->id]);
        ActivityLogger::log('users.time_off', $off->user, "إجازة {$off->user?->name} من {$data['starts_on']} إلى {$data['ends_on']}");

        return back()->with('success', 'سُجّلت الإجازة.');
    }

    public function destroyTimeOff(Request $request, TimeOff $timeOff): RedirectResponse
    {
        abort_unless((int) $timeOff->user_id === $request->user()->id || $this->canManage($request->user()), 403);
        $timeOff->delete();

        return back();
    }

    /** People the user may see here: all (tasks.assign = all), their projects' teams (assigned), or themselves. */
    private function people(User $user): Collection
    {
        $scope = $user->permissionScope('tasks.assign');
        $q = User::where('is_active', true)->orderBy('name');
        if ($scope === 'all' || $user->permissionScope('tasks.approve_time') === 'all') {
            return $q->get()->filter(fn (User $u) => $u->hasPermission('tasks.view'))->values();
        }
        if ($scope === 'assigned') {
            $ids = Task::projectsOf($user)->get()->pluck('id');
            $members = \Illuminate\Support\Facades\DB::table('project_members')->whereIn('project_id', $ids)->pluck('user_id')
                ->merge(Project::whereIn('id', $ids)->pluck('manager_id'))->push($user->id)->filter()->unique();

            return $q->whereIn('id', $members)->get();
        }

        return $q->whereKey($user->id)->get();
    }

    private function canManage(User $user): bool
    {
        return $user->hasPermission('users.manage') || $user->permissionScope('tasks.assign') === 'all';
    }
}
