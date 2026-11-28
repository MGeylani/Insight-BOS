<?php

namespace App\Services\Tasks;

use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Models\TaskList;
use App\Models\TaskStatus;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Notifications\TaskNotification;
use App\Services\ActivityLogger;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Automations: trigger -> conditions -> actions, run synchronously (no queue on the server).
 *
 * Triggers fired from the task services: task_created, status_changed, assignee_added, field_changed,
 * comment_added. Time-based ones run from `tasks:automations` (every 15 minutes): due_soon, overdue,
 * status_stale (unchanged for N days), and monthly (first day of the month, no task).
 * Actions done by an automation can fire other automations, at most three levels deep.
 */
class Automations
{
    public const TRIGGERS = [
        'task_created' => 'إنشاء مهمة', 'status_changed' => 'تغيّر الحالة', 'assignee_added' => 'إسناد مسؤول',
        'field_changed' => 'تغيّر حقل مخصص', 'comment_added' => 'تعليق جديد', 'due_soon' => 'اقتراب الموعد',
        'overdue' => 'تجاوز الموعد', 'status_stale' => 'بقاء الحالة دون تغيير', 'monthly' => 'بداية كل شهر',
    ];

    public const ACTIONS = [
        'assign' => 'إسناد إلى', 'set_status' => 'تغيير الحالة', 'set_priority' => 'تغيير الأولوية', 'set_field' => 'تعيين حقل',
        'increment_field' => 'زيادة حقل رقمي', 'add_tag' => 'إضافة وسم', 'set_due' => 'تحديد التسليم بعد أيام',
        'create_subtask' => 'إنشاء مهمة فرعية', 'create_task' => 'إنشاء مهمة', 'apply_template' => 'تطبيق قالب',
        'move_to_list' => 'نقل إلى قائمة', 'notify' => 'إشعار',
    ];

    private static int $depth = 0;

    /** Fire an event-based trigger for a task. @param array<string, mixed> $ctx */
    public static function fire(string $trigger, Task $task, array $ctx = []): void
    {
        if (self::$depth >= 3) {
            return;
        }
        $autos = Automation::where('is_active', true)->where('trigger', $trigger)->get();
        foreach ($autos as $a) {
            if ($a->covers($task) && self::triggerMatches($a, $ctx)) {
                self::run($a, $task, $ctx);
            }
        }
    }

    /** Time-based triggers. @return int runs */
    public static function tick(): int
    {
        $n = 0;
        foreach (Automation::where('is_active', true)->whereIn('trigger', ['due_soon', 'overdue', 'status_stale', 'monthly'])->get() as $a) {
            $cfg = $a->trigger_config ?? [];
            if ($a->trigger === 'monthly') {
                $key = 'month:'.now()->format('Y-m');
                if (now()->day === 1 && ! self::ran($a, null, $key)) {
                    self::run($a, null, [], $key);
                    $n++;
                }

                continue;
            }
            $q = self::scoped($a, Task::query()->open());
            $q = match ($a->trigger) {
                'due_soon' => $q->whereNotNull('due_at')->whereBetween('due_at', [now(), now()->addHours(max(1, (int) ($cfg['hours'] ?? 24)))]),
                'overdue' => $q->overdue(),
                'status_stale' => $q->where('updated_at', '<=', now()->subDays(max(1, (int) ($cfg['days'] ?? 2))))
                    ->when(! empty($cfg['status']), fn ($w) => $w->whereIn('status_id', TaskStatus::where('name', $cfg['status'])->select('id'))),
            };
            foreach ($q->limit(500)->get() as $t) {
                $key = $a->trigger === 'status_stale' ? 'stale:'.$t->status_id.':'.$t->updated_at?->format('YmdHi') : $a->trigger.':'.$t->due_at?->format('YmdHi');
                if (! self::ran($a, $t, $key)) {
                    self::run($a, $t, [], $key);
                    $n++;
                }
            }
        }

        return $n;
    }

    /** Run the automation's actions on a task if its conditions hold. */
    public static function run(Automation $a, ?Task $task, array $ctx = [], ?string $dedupe = null): void
    {
        if ($task && ! self::conditionsHold($a->conditions ?? [], $task)) {
            return;
        }
        self::$depth++;
        $done = [];
        try {
            foreach ($a->actions as $action) {
                $done[] = self::act($action, $task, $a);
                $task?->refresh();
            }
            self::log($a, $task, 'ok', implode(' · ', array_filter($done)), $dedupe);
        } catch (\Throwable $e) {
            $msg = $e instanceof ValidationException ? collect($e->errors())->flatten()->implode(' ') : $e->getMessage();
            self::log($a, $task, 'error', mb_substr($msg, 0, 480), $dedupe);
        } finally {
            self::$depth--;
        }
    }

    private static function triggerMatches(Automation $a, array $ctx): bool
    {
        $cfg = $a->trigger_config ?? [];

        return match ($a->trigger) {
            'status_changed' => (empty($cfg['to']) || ($ctx['to'] ?? null) === $cfg['to'])
                && (empty($cfg['from']) || ($ctx['from'] ?? null) === $cfg['from'])
                && (empty($cfg['to_group']) || ($ctx['to_group'] ?? null) === $cfg['to_group']),
            'field_changed' => empty($cfg['field_id']) || (int) ($ctx['field_id'] ?? 0) === (int) $cfg['field_id'],
            'assignee_added' => empty($cfg['user_id']) || in_array((int) $cfg['user_id'], $ctx['users'] ?? [], true),
            default => true,
        };
    }

    /** @param list<array{field: string, op: string, value?: mixed}> $conditions */
    public static function conditionsHold(array $conditions, Task $t): bool
    {
        foreach ($conditions as $c) {
            $field = (string) ($c['field'] ?? '');
            $val = match (true) {
                $field === 'status' => $t->status?->name,
                $field === 'status_group' => $t->status?->group,
                $field === 'priority' => $t->priority,
                $field === 'type' => $t->task_type_id,
                $field === 'tag' => $t->tags()->pluck('tags.id')->all(),
                $field === 'assignee' => $t->assignees()->pluck('users.id')->all(),
                str_starts_with($field, 'field:') => CustomFieldValue::where('task_id', $t->id)->where('custom_field_id', (int) substr($field, 6))->first()?->value,
                default => null,
            };
            $want = $c['value'] ?? null;
            $ok = match ($c['op'] ?? 'is') {
                'is' => is_array($val) ? in_array($want, $val) : (string) $val === (string) $want,
                'is_not' => is_array($val) ? ! in_array($want, $val) : (string) $val !== (string) $want,
                'empty' => $val === null || $val === '' || $val === [],
                'not_empty' => ! ($val === null || $val === '' || $val === []),
                'gt' => is_numeric($val) && (float) $val > (float) $want,
                'lt' => is_numeric($val) && (float) $val < (float) $want,
                default => false,
            };
            if (! $ok) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $x */
    private static function act(array $x, ?Task $t, Automation $a): ?string
    {
        $type = $x['type'] ?? '';
        $needTask = fn () => $t ?? throw ValidationException::withMessages(['task' => 'هذا الإجراء يحتاج مهمة.']);
        $user = auth()->user() ?? User::where('is_super_admin', true)->orderBy('id')->first() ?? User::orderBy('id')->first();

        switch ($type) {
            case 'assign':
                $task = $needTask();
                $ids = self::people($x['users'] ?? [], $task);
                $current = $task->assignees()->pluck('users.id')->all();
                Tasks::syncPeople($task, 'assignees', ($x['mode'] ?? 'add') === 'replace' ? $ids : array_merge($current, $ids));

                return 'إسناد';
            case 'set_status':
                $task = $needTask();
                $s = TaskStatus::where('status_scheme_id', $task->list->status_scheme_id)->where('name', $x['status'] ?? '')->first();
                if (! $s) {
                    throw ValidationException::withMessages(['status' => 'الحالة «'.($x['status'] ?? '').'» غير موجودة في قائمة المهمة.']);
                }
                Tasks::update($task, ['status_id' => $s->id, 'force' => true]);

                return 'الحالة ← '.$s->name;
            case 'set_priority':
                Tasks::update($needTask(), ['priority' => $x['priority'] ?? 'normal']);

                return 'الأولوية';
            case 'set_field':
                CustomFields::setValue($needTask(), CustomField::findOrFail($x['field_id'] ?? 0), $x['value'] ?? null);

                return 'حقل';
            case 'increment_field':
                $task = $needTask();
                $f = CustomField::findOrFail($x['field_id'] ?? 0);
                $cur = CustomFieldValue::where('task_id', $task->id)->where('custom_field_id', $f->id)->value('value');
                CustomFields::setValue($task, $f, (float) $cur + (float) ($x['by'] ?? 1));

                return "{$f->name} +".($x['by'] ?? 1);
            case 'add_tag':
                $task = $needTask();
                Tasks::syncTags($task, array_merge($task->tags()->pluck('tags.id')->all(), Tag::whereKey($x['tag_id'] ?? 0)->pluck('id')->all()));

                return 'وسم';
            case 'set_due':
                Tasks::update($needTask(), ['due_date' => today()->addDays((int) ($x['days'] ?? 1))->toDateString(), 'due_time' => null]);

                return 'التسليم';
            case 'create_subtask':
                $task = $needTask();
                if ($task->depth >= (int) config('tasks.max_depth')) {
                    throw ValidationException::withMessages(['parent' => 'لا يمكن إضافة مستوى رابع.']);
                }
                $new = Tasks::create($task->list, ['title' => self::text($x['title'] ?? 'مهمة فرعية', $task), 'parent_id' => $task->id,
                    'assignees' => self::people($x['users'] ?? [], $task) ?: $task->assignees()->pluck('users.id')->all(),
                    'due_date' => isset($x['due_in_days']) ? today()->addDays((int) $x['due_in_days'])->toDateString() : null], $user);

                return 'فرعية '.$new->code();
            case 'create_task':
                $list = TaskList::findOrFail($x['list_id'] ?? 0);
                $new = Tasks::create($list, ['title' => self::text($x['title'] ?? 'مهمة', $t),
                    'assignees' => $t ? self::people($x['users'] ?? [], $t) : array_map('intval', array_filter((array) ($x['users'] ?? []), 'is_numeric')),
                    'due_date' => isset($x['due_in_days']) ? today()->addDays((int) $x['due_in_days'])->toDateString() : null], $user);

                return 'مهمة '.$new->code();
            case 'apply_template':
                $tpl = TaskTemplate::findOrFail($x['template_id'] ?? 0);
                if ($tpl->type === 'task') {
                    $task = $needTask();
                    Templates::applyTask($tpl, $task->list, $user, ($x['as'] ?? 'subtask') === 'subtask' ? $task : null);
                } else {
                    $project = Project::find($x['project_id'] ?? $t?->project_id ?? ($a->scope_type === 'project' ? $a->scope_id : 0))
                        ?? throw ValidationException::withMessages(['project' => 'لا مشروع لتطبيق القالب عليه.']);
                    $name = isset($x['list_name']) ? str_replace('{month}', now()->locale('ar')->translatedFormat('F Y'), $x['list_name']) : null;
                    $tpl->type === 'list' ? Templates::applyList($tpl, $project->client, $project, $user, $name) : Templates::applyProject($tpl, $project, $user);
                }

                return 'قالب '.$tpl->name;
            case 'move_to_list':
                Tasks::moveToList($needTask(), TaskList::findOrFail($x['list_id'] ?? 0));

                return 'نقل';
            case 'notify':
                $ids = $t ? self::people($x['users'] ?? ['assignees'], $t) : array_map('intval', array_filter((array) ($x['users'] ?? []), 'is_numeric'));
                $title = self::text($x['message'] ?? 'تنبيه من الأتمتة: {code} {title}', $t);
                foreach (User::whereIn('id', $ids)->where('is_active', true)->get() as $u) {
                    if (! $t || $t->isVisibleTo($u)) {
                        $u->notify(new TaskNotification(['kind' => 'task.automation', 'title' => $title, 'task_id' => $t?->id, 'url' => $t ? "/tasks/{$t->id}" : '/tasks', 'body' => $a->name]));
                    }
                }

                return 'إشعار';
        }
        throw ValidationException::withMessages(['type' => "إجراء غير معروف: $type"]);
    }

    /** User ids from ids and the keywords assignees / watchers / creator / project_manager. @return list<int> */
    private static function people(array $values, Task $t): array
    {
        $ids = [];
        foreach ($values as $v) {
            $ids = array_merge($ids, match ((string) $v) {
                'assignees' => $t->assignees()->pluck('users.id')->all(),
                'watchers' => $t->watchers()->pluck('users.id')->all(),
                'creator' => array_filter([$t->created_by]),
                'project_manager' => array_filter([$t->project?->manager_id]),
                default => is_numeric($v) ? [(int) $v] : [],
            });
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /** {code} {title} {status} {list} in texts. */
    private static function text(string $s, ?Task $t): string
    {
        return $t ? strtr($s, ['{code}' => $t->code(), '{title}' => $t->title, '{status}' => (string) $t->status?->name, '{list}' => (string) $t->list?->name]) : $s;
    }

    private static function scoped(Automation $a, $q)
    {
        return match ($a->scope_type) {
            'client' => $q->where('client_id', $a->scope_id),
            'project' => $q->where('project_id', $a->scope_id),
            'list' => $q->where('task_list_id', $a->scope_id),
            default => $q,
        };
    }

    private static function ran(Automation $a, ?Task $t, string $key): bool
    {
        return AutomationRun::where('automation_id', $a->id)->where('task_id', $t?->id)->where('dedupe_key', $key)->exists();
    }

    private static function log(Automation $a, ?Task $t, string $status, string $message, ?string $dedupe): void
    {
        AutomationRun::create(['automation_id' => $a->id, 'task_id' => $t?->id, 'status' => $status, 'message' => $message, 'dedupe_key' => $dedupe]);
        $a->forceFill(['runs_count' => $a->runs_count + 1, 'last_run_at' => now()])->saveQuietly();
        if ($t && $status === 'ok') {
            ActivityLogger::log('tasks.automation', $t, "أتمتة «{$a->name}»: $message");
        }
    }

    /** Validate and normalize an automation definition. @return array<string, mixed> */
    public static function clean(array $d): array
    {
        if (! isset(self::TRIGGERS[$d['trigger'] ?? ''])) {
            throw ValidationException::withMessages(['trigger' => 'المُشغِّل غير معروف.']);
        }
        $actions = array_values(array_filter((array) ($d['actions'] ?? []), fn ($x) => isset(self::ACTIONS[$x['type'] ?? ''])));
        if (! $actions) {
            throw ValidationException::withMessages(['actions' => 'أضف إجراءً واحداً على الأقل.']);
        }
        if ($d['trigger'] === 'monthly') {
            foreach ($actions as $x) {
                if (! in_array($x['type'], ['create_task', 'apply_template', 'notify'], true)) {
                    throw ValidationException::withMessages(['actions' => 'مُشغِّل «بداية كل شهر» لا يتعلق بمهمة: استخدم إنشاء مهمة أو تطبيق قالب أو إشعار.']);
                }
            }
        }

        return [
            'trigger_config' => array_filter((array) ($d['trigger_config'] ?? []), fn ($v) => $v !== null && $v !== ''),
            'conditions' => array_values(array_filter((array) ($d['conditions'] ?? []), fn ($c) => ! empty($c['field']))),
            'actions' => $actions,
        ];
    }

    /** Ready-made agency automations (filled in and saved from the automations page). @return list<array<string, mixed>> */
    public static function recipes(): array
    {
        return [
            ['key' => 'review', 'name' => 'مراجعة داخلية ← مهمة مراجعة للمدير الإبداعي', 'trigger' => 'status_changed', 'trigger_config' => ['to' => 'مراجعة داخلية'],
                'actions' => [['type' => 'create_subtask', 'title' => 'مراجعة إبداعية: {title}', 'users' => [], 'due_in_days' => 1]], 'needs' => 'اختر المدير الإبداعي في الإجراء'],
            ['key' => 'client_wait', 'name' => 'بانتظار العميل يومين دون رد ← تذكير مدير المشروع', 'trigger' => 'status_stale', 'trigger_config' => ['status' => 'بانتظار العميل', 'days' => 2],
                'actions' => [['type' => 'notify', 'users' => ['project_manager', 'assignees'], 'message' => 'لا رد من العميل منذ يومين: {code} {title}']]],
            ['key' => 'revisions', 'name' => 'تعديلات ← عدّاد جولات التعديل +1', 'trigger' => 'status_changed', 'trigger_config' => ['to' => 'تعديلات'],
                'actions' => [['type' => 'increment_field', 'field_id' => null, 'by' => 1]], 'needs' => 'أنشئ حقلاً رقمياً «جولات التعديل» واختره'],
            ['key' => 'revisions_limit', 'name' => 'تجاوز الجولات المتفق عليها ← تنبيه مدير المشروع', 'trigger' => 'field_changed', 'trigger_config' => ['field_id' => null],
                'conditions' => [['field' => 'field:', 'op' => 'gt', 'value' => 2]],
                'actions' => [['type' => 'notify', 'users' => ['project_manager'], 'message' => 'تجاوزت جولات التعديل المتفق عليها: {code} {title}']], 'needs' => 'اختر حقل الجولات والحد'],
            ['key' => 'overdue', 'name' => 'تجاوز الموعد ← إشعار المسؤول ومدير المشروع', 'trigger' => 'overdue',
                'actions' => [['type' => 'notify', 'users' => ['assignees', 'project_manager'], 'message' => 'تجاوزت موعدها: {code} {title}']]],
            ['key' => 'monthly', 'name' => 'بداية كل شهر ← قائمة الشهر من قالب (عقد شهري)', 'trigger' => 'monthly',
                'actions' => [['type' => 'apply_template', 'template_id' => null, 'list_name' => 'محتوى {month}']], 'needs' => 'اجعل نطاقها المشروع واختر قالب القائمة'],
        ];
    }
}
