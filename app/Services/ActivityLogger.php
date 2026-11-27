<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;

class ActivityLogger
{
    /**
     * @param  array<string, mixed>|null  $changes
     */
    public static function log(string $action, ?Model $subject = null, ?string $description = null, ?array $changes = null): void
    {
        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'description' => $description,
            'changes' => $changes,
            'ip_address' => request()?->ip(),
            'created_at' => now(),
        ]);
    }

    /**
     * Diff of dirty attributes, safe for logging (secrets removed).
     *
     * @return array<string, array{from: mixed, to: mixed}>
     */
    public static function diff(Model $model): array
    {
        $hidden = ['password', 'remember_token', 'credentials'];
        $out = [];
        foreach ($model->getChanges() as $key => $value) {
            if (in_array($key, $hidden, true) || $key === 'updated_at') {
                continue;
            }
            $out[$key] = ['from' => $model->getOriginal($key), 'to' => $value];
        }

        return $out;
    }
}
