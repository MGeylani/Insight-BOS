<?php

namespace App\Services\Tasks;

use App\Models\CustomField;
use App\Models\IntakeForm;
use App\Models\Task;
use App\Models\TaskList;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Storage\Attachments;
use App\Services\Storage\StorageManager;
use App\Support\RichText;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Intake forms: a request form (internal, or public for clients and the website) that creates a task
 * in a list, with the standard fields it asks for and the list's custom fields.
 *
 * Field keys: title (always), description, due, priority, files, name and email (who is asking, for
 * public forms), and cf_<id> for a custom field of the list.
 */
class IntakeForms
{
    public const STANDARD = [
        'title' => 'العنوان', 'description' => 'التفاصيل', 'due' => 'الموعد المطلوب', 'priority' => 'الأولوية',
        'files' => 'ملفات', 'name' => 'الاسم', 'email' => 'البريد الإلكتروني',
    ];

    /** Custom field types a form can ask for. */
    public const FIELD_TYPES = ['text', 'number', 'money', 'percent', 'dropdown', 'labels', 'date', 'checkbox', 'url', 'rating', 'file'];

    /** Custom fields of a list that a form can use. */
    public static function customFields(TaskList $list)
    {
        return CustomFields::forLocation(['type' => 'list', 'id' => $list->id])->whereIn('type', self::FIELD_TYPES)->values();
    }

    /** Validate and normalize a form definition. @return array<string, mixed> */
    public static function clean(array $d, TaskList $list): array
    {
        $cf = self::customFields($list)->keyBy('id');
        $fields = [];
        $seen = [];
        foreach ((array) ($d['fields'] ?? []) as $f) {
            $key = (string) ($f['key'] ?? '');
            $valid = isset(self::STANDARD[$key]) || (str_starts_with($key, 'cf_') && $cf->has((int) substr($key, 3)));
            if (! $valid || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $fields[] = [
                'key' => $key,
                'label' => mb_substr(trim((string) ($f['label'] ?? '')), 0, 120) ?: (self::STANDARD[$key] ?? $cf[(int) substr($key, 3)]->name),
                'required' => $key === 'title' || filter_var($f['required'] ?? false, FILTER_VALIDATE_BOOL),
                'help' => mb_substr(trim((string) ($f['help'] ?? '')), 0, 300) ?: null,
            ];
        }
        if (! isset($seen['title'])) {
            array_unshift($fields, ['key' => 'title', 'label' => self::STANDARD['title'], 'required' => true, 'help' => null]);
        }
        $def = (array) ($d['defaults'] ?? []);

        return [
            'fields' => $fields,
            'defaults' => array_filter([
                'assignees' => User::whereIn('id', (array) ($def['assignees'] ?? []))->where('is_active', true)->pluck('id')->all(),
                'tags' => \App\Models\Tag::whereIn('id', (array) ($def['tags'] ?? []))->pluck('id')->all(),
                'type_id' => Tasks::typeId($def['type_id'] ?? null),
                'priority' => isset(config('tasks.priorities')[$def['priority'] ?? '']) ? $def['priority'] : null,
                'due_in_days' => is_numeric($def['due_in_days'] ?? null) ? max(0, min(365, (int) $def['due_in_days'])) : null,
            ], fn ($v) => $v !== null && $v !== []),
        ];
    }

    public static function create(TaskList $list, array $d, User $user): IntakeForm
    {
        $form = IntakeForm::create([
            'name' => $d['name'], 'description' => $d['description'] ?? null, 'task_list_id' => $list->id,
            'token' => Str::random(32), 'is_public' => (bool) ($d['is_public'] ?? false), 'is_active' => true,
            'confirmation' => $d['confirmation'] ?? null, 'created_by' => $user->id,
            ...self::clean($d, $list),
        ]);
        ActivityLogger::log('tasks.form_saved', $form, "إنشاء نموذج الاستقبال «{$form->name}»");

        return $form;
    }

    /**
     * Create the task from a submission.
     *
     * @param  array<string, mixed>  $answers
     * @param  array<string, list<UploadedFile>>  $files  by field key
     */
    public static function submit(IntakeForm $form, array $answers, array $files, ?User $user): Task
    {
        if (! $form->is_active) {
            throw ValidationException::withMessages(['form' => 'هذا النموذج متوقف حالياً.']);
        }
        $list = $form->list()->firstOrFail();
        $cf = self::customFields($list)->keyBy('id');
        $errors = [];
        $val = fn ($k) => is_string($answers[$k] ?? null) ? trim($answers[$k]) : ($answers[$k] ?? null);
        foreach ($form->fields as $f) {
            $k = $f['key'];
            $empty = in_array($k, ['files'], true) || ($cf->get((int) substr($k, 3))?->type === 'file')
                ? empty($files[$k]) : ($val($k) === null || $val($k) === '' || $val($k) === []);
            if ($f['required'] && $empty) {
                $errors["answers.$k"] = "«{$f['label']}» مطلوب.";
            }
        }
        $keys = collect($form->fields)->pluck('key');
        if ($keys->contains('email') && $val('email') && ! filter_var($val('email'), FILTER_VALIDATE_EMAIL)) {
            $errors['answers.email'] = 'البريد الإلكتروني غير صالح.';
        }
        if ($keys->contains('due') && $val('due') && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $val('due'))) {
            $errors['answers.due'] = 'تاريخ غير صالح.';
        }
        $hasFiles = collect($files)->flatten()->isNotEmpty();
        if ($hasFiles && ! StorageManager::connected()) {
            $errors['answers.files'] = 'لا يمكن استلام الملفات الآن (لا توجد خدمة تخزين مربوطة).';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $creator = $user ?? $form->creator ?? User::where('is_super_admin', true)->orderBy('id')->firstOrFail();
        $def = $form->defaults ?? [];
        $task = DB::transaction(function () use ($form, $list, $answers, $val, $keys, $cf, $creator, $user, $def) {
            $who = trim(implode(' ', array_filter([$keys->contains('name') ? $val('name') : null, $keys->contains('email') && $val('email') ? '<'.$val('email').'>' : null])));
            $text = trim(($keys->contains('description') ? (string) $val('description') : '')
                ."\n\n".($user ? '' : 'مقدّم الطلب: '.($who ?: 'غير معروف'))."\nمن نموذج «{$form->name}»");
            $due = $keys->contains('due') && $val('due') ? $val('due') : (isset($def['due_in_days']) ? today()->addDays($def['due_in_days'])->toDateString() : null);
            $task = Tasks::create($list, [
                'title' => mb_substr((string) $val('title'), 0, 250),
                'description' => RichText::fromPlain($text),
                'priority' => $keys->contains('priority') && isset(config('tasks.priorities')[$val('priority') ?? '']) ? $val('priority') : ($def['priority'] ?? 'normal'),
                'due_date' => $due,
                'task_type_id' => $def['type_id'] ?? null,
                'tags' => $def['tags'] ?? [],
                'assignees' => $def['assignees'] ?? [],
            ], $creator);
            foreach ($keys->filter(fn ($k) => str_starts_with($k, 'cf_')) as $k) {
                $field = $cf->get((int) substr($k, 3));
                if (! $field || $field->type === 'file' || $val($k) === null || $val($k) === '') {
                    continue;
                }
                try {
                    CustomFields::setValue($task, $field, $val($k));
                } catch (ValidationException $e) {
                    throw ValidationException::withMessages(["answers.$k" => "«{$field->name}»: ".collect($e->errors())->flatten()->first()]);
                }
            }
            $form->forceFill(['submissions_count' => $form->submissions_count + 1, 'last_submitted_at' => now()])->saveQuietly();
            ActivityLogger::log('tasks.form_submitted', $task, "من نموذج الاستقبال «{$form->name}»".($user ? '' : ' (رابط عام)'));

            return $task;
        });

        // Nobody assigned by default: tell the form's owner there is a new request to triage.
        if (empty($def['assignees']) && $form->created_by) {
            TaskNotifier::send($task, [$form->created_by], 'task.form', "طلب جديد من «{$form->name}»: {$task->code()} {$task->title}");
        }

        // Files go to the storage service after the task exists (an upload is slow and may fail; the task stays).
        foreach ($files as $k => $list_) {
            $ids = [];
            foreach ($list_ as $file) {
                $ids[] = Attachments::store($task, $file, $creator)->id;
            }
            if (str_starts_with($k, 'cf_') && ($field = $cf->get((int) substr($k, 3))) && $field->type === 'file' && $ids) {
                CustomFields::setValue($task, $field, $ids);
            }
        }

        return $task;
    }
}
