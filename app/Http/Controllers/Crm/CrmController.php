<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Currency;
use App\Models\Project;
use App\Models\Quote;
use App\Models\User;

abstract class CrmController extends Controller
{
    /** Vocabularies and pick-lists shared by CRM pages. */
    protected function options(): array
    {
        $stages = collect(config('crm.opportunity_stages'))
            ->map(fn ($s, $k) => ['key' => $k, 'label' => $s['label'], 'probability' => $s['probability']])->values();

        return [
            'sources' => config('crm.lead_sources'),
            'leadStages' => config('crm.lead_stages'),
            'ratings' => config('crm.lead_ratings'),
            'oppStages' => $stages,
            'lostReasons' => config('crm.lost_reasons'),
            'activityTypes' => config('crm.activity_types'),
            'clientTypes' => config('crm.client_types'),
            'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'currencies' => Currency::where('is_active', true)->orderByDesc('is_base')->pluck('code'),
        ];
    }

    /** @return array<string, mixed> */
    protected function activityPayload($a): array
    {
        return [
            'id' => $a->id,
            'type' => $a->type,
            'title' => $a->title,
            'body' => $a->body,
            'due_at' => $a->due_at?->toIso8601String(),
            'remind_at' => $a->remind_at?->toIso8601String(),
            'completed_at' => $a->completed_at?->toIso8601String(),
            'user' => $a->user?->only('id', 'name'),
            'creator' => $a->creator?->only('id', 'name'),
            'created_at' => $a->created_at?->toIso8601String(),
            'overdue' => ! $a->completed_at && $a->due_at && $a->due_at->isPast(),
        ];
    }

    /** Compact quote list for a client or opportunity page, filtered by the viewer's scope. */
    protected function quoteList($relation, User $user): array
    {
        return $relation->visibleTo($user)->with('items')->get()->map(fn (Quote $q) => [
            ...$q->only('id', 'number', 'title', 'currency'),
            'status' => $q->displayStatus(),
            'issue_date' => $q->issue_date?->toDateString(),
            'valid_until' => $q->valid_until?->toDateString(),
            'total' => $q->totals()['total'],
        ])->all();
    }

    /** Compact contract list for a client, opportunity or quote page, filtered by the viewer's scope. */
    protected function contractList($relation, User $user): array
    {
        return $relation->visibleTo($user)->with('schedules')->get()->map(fn (Contract $c) => [
            ...$c->only('id', 'number', 'title', 'type', 'currency'),
            'status' => $c->displayStatus(),
            'start_date' => $c->start_date?->toDateString(),
            'end_date' => $c->end_date?->toDateString(),
            'monthly_fee' => $c->monthly_fee === null ? null : (float) $c->monthly_fee,
            'total' => $c->totalValue(),
        ])->all();
    }

    /** Projects of a client the viewer may see (empty without projects.view). */
    protected function projectList(Client $client, User $user): array
    {
        if (! $user->hasPermission('projects.view')) {
            return [];
        }

        return Project::visibleTo($user)->where('client_id', $client->id)->with('milestones:id,project_id,status')->latest('id')->get()
            ->map(fn (Project $p) => [
                ...$p->only('id', 'number', 'name', 'type', 'status'),
                'overdue' => $p->isOverdue(),
                'due_date' => $p->due_date?->toDateString(),
                'progress' => $p->progress(),
            ])->all();
    }

    /** The project a quote or contract turned into, when the viewer may open it. */
    protected function projectLink(?int $projectId, User $user): ?array
    {
        $project = $projectId ? Project::find($projectId) : null;
        if (! $project) {
            return null;
        }

        return [
            ...$project->only('id', 'number', 'name'),
            'can_open' => $user->hasPermission('projects.view') && $project->isVisibleTo($user, 'projects.view'),
        ];
    }
}
