<?php

namespace App\Services\Crm;

use App\Models\CrmActivity;
use App\Models\Lead;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class LeadMerger
{
    private const FILLABLE_GAPS = ['company_name', 'job_title', 'email', 'phone', 'source', 'rating', 'expected_value', 'interest', 'owner_id'];

    /**
     * Merge $duplicate into $keep: empty fields of $keep are filled from $duplicate, notes are
     * appended, activities move over, and $duplicate is archived with a pointer to $keep.
     */
    public static function merge(Lead $keep, Lead $duplicate): Lead
    {
        if ($keep->is($duplicate)) {
            throw new InvalidArgumentException('Cannot merge a lead into itself.');
        }
        if ($keep->isConverted() || $duplicate->isConverted()) {
            throw new InvalidArgumentException('Converted leads cannot be merged.');
        }

        return DB::transaction(function () use ($keep, $duplicate) {
            $filled = [];
            foreach (self::FILLABLE_GAPS as $field) {
                if (blank($keep->$field) && filled($duplicate->$field)) {
                    $keep->$field = $duplicate->$field;
                    $filled[] = $field;
                }
            }
            if (filled($duplicate->notes)) {
                $keep->notes = trim(($keep->notes ? $keep->notes."\n\n" : '')."— من السجل المدموج #{$duplicate->id}:\n".$duplicate->notes);
            }
            $keep->save();

            $moved = CrmActivity::where('subject_type', 'lead')->where('subject_id', $duplicate->id)
                ->update(['subject_id' => $keep->id]);

            $duplicate->merged_into_id = $keep->id;
            $duplicate->save();
            $duplicate->delete();

            ActivityLogger::log('crm.lead_merged', $keep, "دمج المحتمل #{$duplicate->id} في {$keep->name}", [
                'merged_id' => $duplicate->id,
                'filled' => $filled,
                'activities_moved' => $moved,
            ]);

            return $keep->refresh();
        });
    }
}
