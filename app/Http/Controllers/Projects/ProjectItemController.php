<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Models\ChangeRequest;
use App\Models\Project;
use App\Models\ProjectIssue;
use App\Models\ProjectMilestone;
use App\Models\ProjectRisk;
use App\Services\ActivityLogger;
use App\Services\Projects\Projects;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Milestones, risks, issues and change requests inside a project workspace. */
class ProjectItemController extends Controller
{
    // ---- Milestones ----

    public function storeMilestone(Request $request, Project $project): RedirectResponse
    {
        $this->authorizeRecord($project, 'projects.edit');
        $data = $this->milestoneData($request);
        $m = $project->milestones()->create($data + ['status' => 'pending', 'sort' => (int) $project->milestones()->max('sort') + 1]);
        ActivityLogger::log('projects.milestone', $project, "إضافة مرحلة: {$m->title}");

        return back()->with('success', 'أُضيفت المرحلة.');
    }

    public function updateMilestone(Request $request, Project $project, ProjectMilestone $milestone): RedirectResponse
    {
        $this->authorizeChild($project, $milestone, 'projects.edit');
        $milestone->update($this->milestoneData($request));

        return back()->with('success', 'تم حفظ المرحلة.');
    }

    public function toggleMilestone(Request $request, Project $project, ProjectMilestone $milestone): RedirectResponse
    {
        $this->authorizeChild($project, $milestone, 'projects.edit');
        $data = $request->validate(['done' => ['required', 'boolean']]);
        Projects::setMilestone($milestone, $data['done'], $request->user()->id);

        return back()->with('success', $data['done'] ? 'تم تسجيل إنجاز المرحلة.' : 'أُعيد فتح المرحلة.');
    }

    /** Move a milestone one place up or down. */
    public function moveMilestone(Request $request, Project $project, ProjectMilestone $milestone): RedirectResponse
    {
        $this->authorizeChild($project, $milestone, 'projects.edit');
        $dir = $request->validate(['dir' => ['required', Rule::in(['up', 'down'])]])['dir'];
        $rows = $project->milestones()->get()->values();
        $i = $rows->search(fn ($r) => $r->id === $milestone->id);
        $j = $dir === 'up' ? $i - 1 : $i + 1;
        if ($i !== false && isset($rows[$j])) {
            $order = $rows->pluck('id')->all();
            [$order[$i], $order[$j]] = [$order[$j], $order[$i]];
            foreach ($order as $pos => $id) {
                ProjectMilestone::whereKey($id)->update(['sort' => $pos + 1]);
            }
        }

        return back();
    }

    public function destroyMilestone(Project $project, ProjectMilestone $milestone): RedirectResponse
    {
        $this->authorizeChild($project, $milestone, 'projects.edit');
        $milestone->delete();
        ActivityLogger::log('projects.milestone', $project, "حذف مرحلة: {$milestone->title}");

        return back()->with('success', 'حُذفت المرحلة.');
    }

    // ---- Risks ----

    public function storeRisk(Request $request, Project $project): RedirectResponse
    {
        $this->authorizeRecord($project, 'projects.edit');
        $risk = $project->risks()->create($this->riskData($request) + ['created_by' => $request->user()->id]);
        ActivityLogger::log('projects.risk', $project, "تسجيل خطر: {$risk->title}");

        return back()->with('success', 'سُجّل الخطر.');
    }

    public function updateRisk(Request $request, Project $project, ProjectRisk $risk): RedirectResponse
    {
        $this->authorizeChild($project, $risk, 'projects.edit');
        $risk->update($this->riskData($request));
        if ($changes = ActivityLogger::diff($risk)) {
            ActivityLogger::log('projects.risk', $project, "تحديث الخطر: {$risk->title}", $changes);
        }

        return back()->with('success', 'تم حفظ الخطر.');
    }

    public function destroyRisk(Project $project, ProjectRisk $risk): RedirectResponse
    {
        $this->authorizeChild($project, $risk, 'projects.edit');
        $risk->delete();
        ActivityLogger::log('projects.risk', $project, "حذف الخطر: {$risk->title}");

        return back()->with('success', 'حُذف الخطر.');
    }

    // ---- Issues ----

    public function storeIssue(Request $request, Project $project): RedirectResponse
    {
        $this->authorizeRecord($project, 'projects.edit');
        $issue = new ProjectIssue($this->issueData($request) + ['created_by' => $request->user()->id]);
        $issue->project_id = $project->id;
        $this->stampResolution($issue);
        $issue->save();
        ActivityLogger::log('projects.issue', $project, "تسجيل مشكلة: {$issue->title}");

        return back()->with('success', 'سُجّلت المشكلة.');
    }

    public function updateIssue(Request $request, Project $project, ProjectIssue $issue): RedirectResponse
    {
        $this->authorizeChild($project, $issue, 'projects.edit');
        $issue->fill($this->issueData($request));
        $this->stampResolution($issue);
        $issue->save();
        if ($changes = ActivityLogger::diff($issue)) {
            ActivityLogger::log('projects.issue', $project, ($issue->status === 'resolved' && isset($changes['status']) ? 'حل المشكلة: ' : 'تحديث المشكلة: ').$issue->title, $changes);
        }

        return back()->with('success', 'تم حفظ المشكلة.');
    }

    public function destroyIssue(Project $project, ProjectIssue $issue): RedirectResponse
    {
        $this->authorizeChild($project, $issue, 'projects.edit');
        $issue->delete();
        ActivityLogger::log('projects.issue', $project, "حذف المشكلة: {$issue->title}");

        return back()->with('success', 'حُذفت المشكلة.');
    }

    // ---- Change requests ----

    public function storeChange(Request $request, Project $project): RedirectResponse
    {
        $this->authorizeRecord($project, 'projects.edit');
        $data = $request->validate([
            'title' => ['required', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:5000'],
            'source' => ['required', Rule::in(array_keys(config('projects.change_sources')))],
            'cost_impact' => ['nullable', 'numeric', 'between:-999999999999,999999999999'],
            'days_impact' => ['nullable', 'integer', 'between:-3650,3650'],
        ]);
        $data['cost_impact'] = $data['cost_impact'] ?? 0;
        $data['days_impact'] = $data['days_impact'] ?? 0;
        $cr = Projects::requestChange($project, $data, $request->user()->id);

        return back()->with('success', "سُجّل طلب التغيير {$cr->code()} بانتظار الاعتماد.");
    }

    public function approveChange(Request $request, Project $project, ChangeRequest $change): RedirectResponse
    {
        $this->authorizeChild($project, $change, 'projects.approve_changes');
        $data = $request->validate(['decision_note' => ['nullable', 'string', 'max:2000']]);
        Projects::approveChange($change, $data['decision_note'] ?? null, $request->user()->id);

        return back()->with('success', "اعتُمد طلب التغيير {$change->code()} وحُدّثت الميزانية والجدول.");
    }

    public function rejectChange(Request $request, Project $project, ChangeRequest $change): RedirectResponse
    {
        $this->authorizeChild($project, $change, 'projects.approve_changes');
        $data = $request->validate(['decision_note' => ['required', 'string', 'max:2000']], ['decision_note.required' => 'اكتب سبب الرفض.']);
        Projects::rejectChange($change, $data['decision_note'], $request->user()->id);

        return back()->with('success', "رُفض طلب التغيير {$change->code()}.");
    }

    /** Pending requests can be withdrawn by whoever may edit the project. */
    public function destroyChange(Project $project, ChangeRequest $change): RedirectResponse
    {
        $this->authorizeChild($project, $change, 'projects.edit');
        abort_unless($change->status === 'pending', 422, 'لا يمكن حذف طلب تم البت فيه.');
        $change->delete();
        ActivityLogger::log('projects.change_withdrawn', $project, "سحب طلب التغيير {$change->code()}: {$change->title}");

        return back()->with('success', 'سُحب طلب التغيير.');
    }

    // ---- helpers ----

    private function authorizeChild(Project $project, Model $child, string $permission): void
    {
        abort_unless((int) $child->getAttribute('project_id') === $project->id, 404);
        $this->authorizeRecord($project, $permission);
    }

    private function stampResolution(ProjectIssue $issue): void
    {
        if ($issue->status === 'resolved' && ! $issue->resolved_at) {
            $issue->resolved_at = now();
        } elseif ($issue->status !== 'resolved') {
            $issue->resolved_at = null;
        }
    }

    /** @return array<string, mixed> */
    private function milestoneData(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:190'],
            'details' => ['nullable', 'string', 'max:3000'],
            'due_date' => ['nullable', 'date'],
        ]);
    }

    /** @return array<string, mixed> */
    private function riskData(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:3000'],
            'category' => ['required', Rule::in(array_keys(config('projects.risk_categories')))],
            'probability' => ['required', 'integer', 'between:1,3'],
            'impact' => ['required', 'integer', 'between:1,3'],
            'mitigation' => ['nullable', 'string', 'max:3000'],
            'owner_id' => ['nullable', 'exists:users,id'],
            'status' => ['required', Rule::in(array_keys(config('projects.risk_statuses')))],
        ]);
    }

    /** @return array<string, mixed> */
    private function issueData(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:3000'],
            'severity' => ['required', Rule::in(array_keys(config('projects.issue_severities')))],
            'owner_id' => ['nullable', 'exists:users,id'],
            'due_date' => ['nullable', 'date'],
            'status' => ['required', Rule::in(array_keys(config('projects.issue_statuses')))],
            'resolution' => ['nullable', 'required_if:status,resolved', 'string', 'max:3000'],
        ], ['resolution.required_if' => 'اكتب كيف حُلّت المشكلة.']);
    }
}
