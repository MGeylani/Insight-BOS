<?php

namespace App\Http\Controllers\Tasks;

use App\Http\Controllers\Controller;
use App\Models\Timesheet;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Tasks\Timesheets;
use App\Services\Tasks\TimeTracking;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TimesheetController extends Controller
{
    /** Weeks waiting for approval (and recent decisions) of the people the user may approve. */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $status = in_array($request->input('status'), ['submitted', 'approved', 'rejected', 'draft'], true) ? $request->input('status') : 'submitted';
        $sheets = Timesheet::with(['user:id,name', 'decider:id,name'])->where('status', $status)->latest('week_start')->limit(200)->get()
            ->filter(fn (Timesheet $t) => $t->user && Timesheets::canApprove($user, $t->user))->values();

        return Inertia::render('tasks/Timesheets', [
            'status' => $status,
            'statuses' => Timesheet::STATUSES,
            'sheets' => $sheets->map(function (Timesheet $t) {
                $start = $t->week_start->copy();
                $q = TimeEntry::where('user_id', $t->user_id)->whereBetween('started_at', [$start, $start->copy()->addDays(6)->endOfDay()]);

                return [
                    ...Timesheets::payload($t), 'user' => $t->user->only('id', 'name'), 'week_start' => $start->toDateString(),
                    'totals' => TimeTracking::totals($q),
                ];
            }),
        ]);
    }

    public function submit(Request $request): RedirectResponse
    {
        $data = $request->validate(['week' => ['required', 'date'], 'note' => ['nullable', 'string', 'max:500']]);
        Timesheets::submit($request->user(), $data['week'], $data['note'] ?? null);

        return back()->with('success', 'أُرسل الأسبوع للاعتماد.');
    }

    public function decide(Request $request, Timesheet $timesheet): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject', 'reopen'])], 'note' => ['nullable', 'string', 'max:500']]);
        $owner = User::findOrFail($timesheet->user_id);
        abort_unless(Timesheets::canApprove($request->user(), $owner), 403);
        Timesheets::decide($timesheet, $request->user(), $data['decision'], $data['note'] ?? null);

        return back()->with('success', 'تم.');
    }
}
