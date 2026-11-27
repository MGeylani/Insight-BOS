<?php

namespace App\Services\Crm;

use App\Models\Opportunity;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OpportunityStages
{
    /**
     * Move an opportunity to a stage, record the transition and log it.
     * A lost opportunity always needs a reason; "other" also needs a note.
     */
    public static function move(Opportunity $opp, string $stage, ?int $probability = null, ?string $lostReason = null, ?string $lostNote = null): Opportunity
    {
        if (! array_key_exists($stage, config('crm.opportunity_stages'))) {
            throw ValidationException::withMessages(['stage' => 'مرحلة غير معروفة.']);
        }

        if ($stage === 'lost') {
            if (! $lostReason || ! array_key_exists($lostReason, config('crm.lost_reasons'))) {
                throw ValidationException::withMessages(['lost_reason' => 'سبب الخسارة إلزامي.']);
            }
            if ($lostReason === 'other' && trim((string) $lostNote) === '') {
                throw ValidationException::withMessages(['lost_note' => 'اكتب سبب الخسارة.']);
            }
        }

        // Won and lost have fixed probabilities; open stages may be adjusted by hand.
        $probability = in_array($stage, Opportunity::CLOSED, true) || $probability === null
            ? Opportunity::stageProbability($stage)
            : max(0, min(100, $probability));

        $from = $opp->exists ? $opp->getOriginal('stage') : null;
        if ($opp->exists && $from === $stage && (int) $opp->probability === $probability) {
            return $opp;
        }

        return DB::transaction(function () use ($opp, $stage, $probability, $lostReason, $lostNote, $from) {
            $opp->stage = $stage;
            $opp->probability = $probability;
            $opp->lost_reason = $stage === 'lost' ? $lostReason : null;
            $opp->lost_note = $stage === 'lost' ? $lostNote : null;
            $opp->closed_at = in_array($stage, Opportunity::CLOSED, true) ? ($opp->closed_at ?? now()) : null;
            $opp->save();

            if ($from !== $stage) {
                $opp->stageChanges()->create([
                    'from_stage' => $from,
                    'to_stage' => $stage,
                    'probability' => $probability,
                    'changed_by' => auth()->id(),
                    'created_at' => now(),
                ]);
                if ($from !== null) {
                    $label = config("crm.opportunity_stages.$stage.label");
                    ActivityLogger::log('crm.opportunity_stage', $opp, "نقل الفرصة {$opp->title} إلى «{$label}»", [
                        'stage' => ['from' => $from, 'to' => $stage],
                        'lost_reason' => $opp->lost_reason,
                    ]);
                }
            }

            return $opp;
        });
    }
}
