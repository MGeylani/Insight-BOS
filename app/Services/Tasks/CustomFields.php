<?php

namespace App\Services\Tasks;

use App\Models\Attachment;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskList;
use App\Models\TaskType;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\Formula;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Custom fields: definitions, which ones apply to a task, and validated values.
 *
 * A field applies to a task when its scope covers the task's location (everywhere, its client, its project
 * or its list) and, if the field is linked to task types, the task is of one of those types.
 * Values are stored as JSON with a `sort_value` string used for filters and sorting.
 */
class CustomFields
{
    public const TYPE_LABELS = [
        'text' => 'نص', 'number' => 'رقم', 'money' => 'مبلغ', 'dropdown' => 'قائمة منسدلة', 'labels' => 'تسميات',
        'date' => 'تاريخ', 'checkbox' => 'مربع اختيار', 'people' => 'أشخاص', 'url' => 'رابط', 'rating' => 'تقييم',
        'percent' => 'نسبة', 'file' => 'ملف', 'formula' => 'صيغة',
    ];

    /** Fields that apply to the task, in display order. */
    public static function forTask(Task $task): Collection
    {
        return CustomField::with('types:id')->forLocation($task->client_id, $task->project_id, $task->task_list_id)
            ->orderBy('sort')->orderBy('id')->get()
            ->filter(fn (CustomField $f) => $f->types->isEmpty() || $f->types->contains('id', $task->task_type_id))
            ->values();
    }

    /** Fields defined for a location (for table columns, filters and grouping). */
    public static function forLocation(?array $loc): Collection
    {
        [$clientId, $projectId, $listId] = self::chain($loc);
        $q = CustomField::with('types:id')->orderBy('sort')->orderBy('id');

        return ($loc ? $q->forLocation($clientId, $projectId, $listId) : $q)->get();
    }

    /** client / project / list ids of a location. @return array{0: ?int, 1: ?int, 2: ?int} */
    public static function chain(?array $loc): array
    {
        return match ($loc['type'] ?? null) {
            'list' => [($l = TaskList::find($loc['id']))?->client_id, $l?->project_id, $l?->id],
            'project' => [($p = Project::find($loc['id']))?->client_id, $p?->id, null],
            'client' => [(int) $loc['id'], null, null],
            default => [null, null, null],
        };
    }

    /** Create or change a field definition, including its task types. @param array<string, mixed> $data */
    public static function save(?CustomField $field, array $data, User $user): CustomField
    {
        $type = $field?->type ?? $data['type'];
        $options = self::cleanOptions($type, (array) ($data['options'] ?? []), $field);
        $field ??= new CustomField(['type' => $type, 'created_by' => $user->id]);
        $field->fill([
            'name' => trim($data['name']),
            'options' => $options,
            'scope_type' => $data['scope_type'] ?? $field->scope_type ?? 'all',
            'scope_id' => ($data['scope_type'] ?? $field->scope_type) === 'all' ? null : ($data['scope_id'] ?? $field->scope_id),
        ]);
        if (! $field->exists) {
            $field->sort = (int) CustomField::max('sort') + 1;
        }
        $field->save();
        if (array_key_exists('type_ids', $data)) {
            $field->types()->sync(TaskType::whereIn('id', (array) $data['type_ids'])->pluck('id'));
        }
        ActivityLogger::log('tasks.field_saved', null, "حفظ الحقل المخصص «{$field->name}»");

        return $field->load('types:id');
    }

    /** Validate and store one value (null / empty clears it). */
    public static function setValue(Task $task, CustomField $field, mixed $raw): void
    {
        if ($field->type === 'formula') {
            throw ValidationException::withMessages(['value' => 'حقل الصيغة يُحسب تلقائياً.']);
        }
        if (! self::forTask($task)->contains('id', $field->id)) {
            throw ValidationException::withMessages(['value' => 'هذا الحقل لا ينطبق على المهمة.']);
        }
        $clean = self::clean($task, $field, $raw);
        $old = CustomFieldValue::where('task_id', $task->id)->where('custom_field_id', $field->id)->first();
        if ($clean === null) {
            $old?->delete();
        } else {
            CustomFieldValue::updateOrCreate(['task_id' => $task->id, 'custom_field_id' => $field->id], ['value' => $clean, 'sort_value' => self::sortValue($field, $clean)]);
        }
        if (json_encode($old?->value) !== json_encode($clean)) {
            ActivityLogger::log('tasks.field', $task, "«{$field->name}» ← ".self::display($field, $clean), ['field_id' => $field->id, 'from' => $old?->value, 'to' => $clean]);
            $task->touch();
        }
    }

    /**
     * Values of many tasks, keyed by task id then field id; formula fields are computed.
     *
     * @param  Collection<int, Task>  $tasks
     * @return array<int, array<int, mixed>>
     */
    public static function valuesFor(Collection $tasks): array
    {
        if ($tasks->isEmpty()) {
            return [];
        }
        $out = [];
        foreach (CustomFieldValue::whereIn('task_id', $tasks->pluck('id'))->get(['task_id', 'custom_field_id', 'value']) as $v) {
            $out[$v->task_id][$v->custom_field_id] = $v->value;
        }
        $formulas = self::formulas();
        if ($formulas->isNotEmpty()) {
            foreach ($tasks as $t) {
                $vars = ['estimate' => $t->time_estimate_min ? $t->time_estimate_min / 60 : 0];
                foreach ($out[$t->id] ?? [] as $fid => $val) {
                    $vars[(string) $fid] = is_numeric($val) ? (float) $val : (is_bool($val) ? (int) $val : 0);
                }
                foreach ($formulas as $f) {
                    $r = Formula::evaluate((string) ($f->options['formula'] ?? ''), $vars);
                    if ($r !== null) {
                        $out[$t->id][$f->id] = $r;
                    }
                }
            }
        }

        return $out;
    }

    /** Human-readable value (activity log, CSV). */
    public static function display(CustomField $f, mixed $v): string
    {
        if ($v === null || $v === '' || $v === []) {
            return '—';
        }
        $choice = fn ($id) => collect($f->options['choices'] ?? [])->firstWhere('id', $id)['label'] ?? $id;

        return match ($f->type) {
            'dropdown' => (string) $choice($v),
            'labels' => collect($v)->map($choice)->implode('، '),
            'checkbox' => $v ? '✓' : '✗',
            'people' => User::whereIn('id', (array) $v)->pluck('name')->implode('، '),
            'money' => number_format((float) $v, 2).' '.($f->options['currency'] ?? 'USD'),
            'percent' => $v.'%',
            'rating' => str_repeat('★', (int) $v),
            'file' => Attachment::whereIn('id', (array) $v)->pluck('name')->implode('، '),
            'date' => str_replace('T', ' ', (string) $v),
            default => is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE),
        };
    }

    public static function canManage(User $user, string $scopeType, ?int $scopeId): bool
    {
        if ($user->hasPermission('settings.manage') || $user->permissionScope('tasks.edit') === 'all') {
            return true;
        }

        return match ($scopeType) {
            'project' => $user->hasPermission('tasks.edit') && (int) Project::whereKey($scopeId)->value('manager_id') === $user->id,
            'list' => (bool) TaskList::find($scopeId)?->canManage($user),
            default => false,
        };
    }

    /** @return Collection<int, CustomField> */
    private static function formulas(): Collection
    {
        return CustomField::where('type', 'formula')->get();
    }

    private static function clean(Task $task, CustomField $f, mixed $v): mixed
    {
        if ($v === null || $v === '' || $v === []) {
            return null;
        }
        $fail = fn (string $msg) => throw ValidationException::withMessages(['value' => $msg]);
        $o = $f->options ?? [];
        $choiceIds = collect($o['choices'] ?? [])->pluck('id')->all();

        return match ($f->type) {
            'text' => mb_substr(trim((string) $v), 0, 2000) ?: null,
            'number', 'money' => is_numeric($v) ? round((float) $v, (int) ($o['decimals'] ?? 2)) : $fail('أدخل رقماً.'),
            'percent' => is_numeric($v) && $v >= 0 && $v <= 100 ? round((float) $v, 2) : $fail('النسبة بين 0 و100.'),
            'rating' => is_numeric($v) && (int) $v >= 0 && (int) $v <= (int) ($o['max'] ?? 5) ? ((int) $v ?: null) : $fail('تقييم غير صالح.'),
            'checkbox' => filter_var($v, FILTER_VALIDATE_BOOL),
            'dropdown' => in_array($v, $choiceIds, true) ? $v : $fail('اختيار غير موجود.'),
            'labels' => array_values(array_intersect($choiceIds, (array) $v)) ?: null,
            'people' => User::whereIn('id', (array) $v)->where('is_active', true)->pluck('id')->all() ?: null,
            'url' => preg_match('#^https?://\S+$#i', trim((string) $v)) ? mb_substr(trim((string) $v), 0, 1000) : $fail('الرابط يبدأ بـ http:// أو https://'),
            'date' => preg_match('/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2})?$/', (string) $v) && strtotime(str_replace('T', ' ', $v)) ? (string) $v : $fail('تاريخ غير صالح.'),
            'file' => Attachment::where('attachable_type', 'task')->where('attachable_id', $task->id)->whereIn('id', (array) $v)->pluck('id')->all() ?: null,
            default => $fail('نوع غير مدعوم.'),
        };
    }

    private static function sortValue(CustomField $f, mixed $v): ?string
    {
        return match ($f->type) {
            'number', 'money', 'percent', 'rating' => sprintf('%020.4f', (float) $v + 1e12), // sortable as text, negatives included
            'checkbox' => $v ? '1' : '0',
            'labels', 'people', 'file' => ','.implode(',', (array) $v).',',
            'date' => (string) $v,
            default => mb_substr((string) $v, 0, 191),
        };
    }

    /** @return array<string, mixed>|null */
    private static function cleanOptions(string $type, array $o, ?CustomField $existing): ?array
    {
        if (in_array($type, ['dropdown', 'labels'], true)) {
            $choices = [];
            foreach ((array) ($o['choices'] ?? []) as $c) {
                $label = trim((string) ($c['label'] ?? ''));
                if ($label === '') {
                    continue;
                }
                $choices[] = [
                    'id' => (string) ($c['id'] ?? '') ?: Str::lower(Str::random(6)),
                    'label' => mb_substr($label, 0, 80),
                    'color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($c['color'] ?? '')) ? $c['color'] : '#64748b',
                ];
            }
            if (! $choices) {
                throw ValidationException::withMessages(['options' => 'أضف خياراً واحداً على الأقل.']);
            }

            return ['choices' => $choices];
        }

        return match ($type) {
            'money' => ['currency' => \App\Models\Currency::where('code', $cur = (string) ($o['currency'] ?? 'USD'))->exists() ? $cur : 'USD', 'decimals' => 2],
            'number' => ['decimals' => max(0, min(4, (int) ($o['decimals'] ?? 0)))],
            'rating' => ['max' => max(3, min(10, (int) ($o['max'] ?? 5)))],
            'formula' => self::cleanFormula((string) ($o['formula'] ?? ''), $existing),
            default => null,
        };
    }

    /** @return array{formula: string} */
    private static function cleanFormula(string $expr, ?CustomField $existing): array
    {
        $expr = trim($expr);
        $refs = Formula::references($expr);
        $vars = ['estimate' => 1] + array_fill_keys(array_map('strval', $refs), 1);
        if ($expr === '' || Formula::evaluate($expr, $vars) === null) {
            throw ValidationException::withMessages(['options' => 'الصيغة غير صحيحة. مثال: {12} * {13} أو {estimate} * 25']);
        }
        $numeric = CustomField::whereIn('id', $refs)->whereIn('type', ['number', 'money', 'percent', 'rating', 'checkbox'])->count();
        if ($numeric !== count($refs) || ($existing && in_array($existing->id, $refs, true))) {
            throw ValidationException::withMessages(['options' => 'الصيغة تستخدم حقولاً رقمية فقط (رقم، مبلغ، نسبة، تقييم، مربع اختيار).']);
        }

        return ['formula' => $expr];
    }
}
