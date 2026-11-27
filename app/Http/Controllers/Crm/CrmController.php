<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

abstract class CrmController extends Controller
{
    /** Abort unless the current user's grant for $permission covers this record. */
    protected function authorizeRecord(Model $record, string $permission): void
    {
        abort_unless(method_exists($record, 'isVisibleTo') && $record->isVisibleTo(request()->user(), $permission), 403);
    }

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
}
