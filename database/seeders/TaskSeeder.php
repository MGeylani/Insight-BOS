<?php

namespace Database\Seeders;

use App\Models\StatusScheme;
use App\Services\Tasks\TaskLists;
use Illuminate\Database\Seeder;

/** Status templates (agency, simple), task types and the internal client "Insight Media". Idempotent. */
class TaskSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('tasks.scheme_templates') as $tpl) {
            $scheme = StatusScheme::firstOrCreate(
                ['name' => $tpl['name'], 'is_template' => true],
                ['is_default' => $tpl['default'] && ! StatusScheme::where('is_template', true)->where('is_default', true)->exists()],
            );
            if ($scheme->wasRecentlyCreated) {
                foreach ($tpl['statuses'] as $i => $row) {
                    $scheme->statuses()->create($row + ['sort' => $i + 1]);
                }
            }
        }

        foreach (config('tasks.types') as $i => $t) {
            \App\Models\TaskType::firstOrCreate(['name' => $t['name']], $t + [
                'sort' => $i + 1,
                'is_default' => $i === 0 && ! \App\Models\TaskType::where('is_default', true)->exists(),
            ]);
        }

        TaskLists::internalClient();
    }
}
