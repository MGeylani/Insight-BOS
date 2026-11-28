<?php

namespace App\Services\Tasks;

use App\Models\AccessGrant;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskList;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Sharing a client, project or list with a person: their tasks become visible (level view, which also
 * allows commenting) or editable (level edit: also create tasks there), whatever the person's own
 * permission scope. Guests (users.is_guest) see only what is shared with them, never internal tasks
 * or internal comments, and only the task pages (see the RestrictGuests middleware).
 */
class Access
{
    /** Grant levels that give a task permission. @return list<string> */
    public static function levels(string $permission): array
    {
        return match ($permission) {
            'tasks.view' => ['view', 'edit'],
            'tasks.edit', 'tasks.create' => ['edit'],
            default => [],
        };
    }

    /** Add "or the task is in something shared with the user" to a where-group on tasks. */
    public static function orTasks(Builder $q, User $user, array $levels): void
    {
        if (! $levels) {
            return;
        }
        $ids = fn (string $type) => AccessGrant::select('scope_id')->where('user_id', $user->id)->where('scope_type', $type)->whereIn('level', $levels);
        $q->orWhereIn('tasks.client_id', $ids('client'))
            ->orWhereIn('tasks.project_id', $ids('project'))
            ->orWhereIn('tasks.task_list_id', $ids('list'))
            ->orWhereIn('tasks.id', DB::table('task_list_links')->select('task_id')->whereIn('task_list_id', $ids('list')));
    }

    /** Same for a where-group on task lists. */
    public static function orLists(Builder $q, User $user, array $levels): void
    {
        if (! $levels) {
            return;
        }
        $ids = fn (string $type) => AccessGrant::select('scope_id')->where('user_id', $user->id)->where('scope_type', $type)->whereIn('level', $levels);
        $q->orWhereIn('task_lists.client_id', $ids('client'))
            ->orWhereIn('task_lists.project_id', $ids('project'))
            ->orWhereIn('task_lists.id', $ids('list'));
    }

    public static function coversTask(Task $t, User $user, array $levels): bool
    {
        if (! $levels) {
            return false;
        }

        return AccessGrant::where('user_id', $user->id)->whereIn('level', $levels)->where(fn ($q) => $q
            ->where(fn ($w) => $w->where('scope_type', 'client')->where('scope_id', $t->client_id))
            ->orWhere(fn ($w) => $w->where('scope_type', 'project')->where('scope_id', $t->project_id))
            ->orWhere(fn ($w) => $w->where('scope_type', 'list')->where(fn ($x) => $x->where('scope_id', $t->task_list_id)
                ->orWhereIn('scope_id', DB::table('task_list_links')->select('task_list_id')->where('task_id', $t->id))))
        )->exists();
    }

    /** Is the task visible to at least one guest (shared place, or a guest assigned to it / who created it)? */
    public static function hasGuests(Task $t): bool
    {
        if ($t->is_internal) {
            return false;
        }
        $guests = User::where('is_guest', true)->where('is_active', true)->select('id');

        return AccessGrant::whereIn('user_id', $guests)->where(fn ($q) => $q
            ->where(fn ($w) => $w->where('scope_type', 'client')->where('scope_id', $t->client_id))
            ->orWhere(fn ($w) => $w->where('scope_type', 'project')->where('scope_id', $t->project_id))
            ->orWhere(fn ($w) => $w->where('scope_type', 'list')->where(fn ($x) => $x->where('scope_id', $t->task_list_id)
                ->orWhereIn('scope_id', DB::table('task_list_links')->select('task_list_id')->where('task_id', $t->id))))
        )->exists()
            || $t->assignees()->where('users.is_guest', true)->exists()
            || User::whereKey($t->created_by)->where('is_guest', true)->exists();
    }

    public static function canShare(User $user, string $type, int $id): bool
    {
        return ! $user->is_guest && ($user->hasPermission('users.manage') || CustomFields::canManage($user, $type, $id));
    }

    /** The thing being shared, or 404. */
    public static function target(string $type, int $id): Client|Project|TaskList
    {
        return match ($type) {
            'client' => Client::findOrFail($id),
            'project' => Project::findOrFail($id),
            'list' => TaskList::findOrFail($id),
            default => abort(404),
        };
    }

    public static function grant(User $by, User $to, string $type, int $id, string $level): AccessGrant
    {
        if (! isset(AccessGrant::LEVELS[$level])) {
            throw ValidationException::withMessages(['level' => 'مستوى غير معروف.']);
        }
        $target = self::target($type, $id);
        $g = AccessGrant::updateOrCreate(['user_id' => $to->id, 'scope_type' => $type, 'scope_id' => $id], ['level' => $level, 'granted_by' => $by->id]);
        ActivityLogger::log('tasks.shared', $target, "مشاركة مع {$to->name}: ".AccessGrant::LEVELS[$level]);

        return $g;
    }

    public static function revoke(AccessGrant $g): void
    {
        $target = self::target($g->scope_type, $g->scope_id);
        $name = $g->user?->name;
        $g->delete();
        ActivityLogger::log('tasks.unshared', $target, "إلغاء المشاركة مع {$name}");
    }

    /**
     * Invite a guest (or reuse the guest with that email) and share with them.
     *
     * @return array{user: User, link: string}
     */
    public static function invite(User $by, string $name, string $email, string $type, int $id, string $level): array
    {
        $email = Str::lower(trim($email));
        $user = User::withTrashed()->where('email', $email)->first();
        if ($user && ! $user->is_guest) {
            throw ValidationException::withMessages(['email' => 'هذا البريد لعضو في الفريق؛ شاركه من قائمة الأشخاص.']);
        }
        if ($user?->trashed()) {
            $user->restore();
        }
        $user ??= tap(new User(['name' => trim($name), 'email' => $email, 'password' => Str::random(40), 'is_active' => true]), function (User $u) {
            $u->forceFill(['is_guest' => true])->save();
        });
        $user->syncPermissions(self::GUEST_PERMISSIONS);
        self::grant($by, $user, $type, $id, $level);

        return ['user' => $user, 'link' => self::passwordLink($user)];
    }

    /** A link to set a password (shared with the guest by the agency: the same as "forgot password"). */
    public static function passwordLink(User $user): string
    {
        return route('password.reset', ['token' => Password::broker()->createToken($user), 'email' => $user->email]);
    }

    /** What every guest may do in general; what they see is decided by their grants. */
    public const GUEST_PERMISSIONS = ['tasks.view' => 'own', 'tasks.create' => 'own', 'tasks.edit' => 'own'];

    /** @return array<string, mixed> */
    public static function payload(string $type, int $id): array
    {
        return [
            'grants' => AccessGrant::with('user:id,name,email,is_guest,is_active')->where('scope_type', $type)->where('scope_id', $id)->orderBy('id')->get()
                ->map(fn (AccessGrant $g) => ['id' => $g->id, 'level' => $g->level, 'user' => $g->user?->only('id', 'name', 'email', 'is_guest', 'is_active')])->values(),
            'levels' => AccessGrant::LEVELS,
            'users' => User::where('is_active', true)->orderBy('is_guest')->orderBy('name')->get(['id', 'name', 'email', 'is_guest']),
        ];
    }
}
