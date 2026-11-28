<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RoleTemplate;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));

        $users = User::with('roleTemplate:id,name')
            ->withCount('grants')
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', "%$search%")->orWhere('email', 'like', "%$search%")))
            ->orderByDesc('is_active')->orderBy('name')
            ->paginate(25)->withQueryString()
            ->through(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'phone' => $u->phone,
                'is_active' => $u->is_active,
                'is_super_admin' => $u->is_super_admin,
                'is_guest' => (bool) $u->is_guest,
                'template' => $u->roleTemplate?->name,
                'grants_count' => $u->grants_count,
                'last_login_at' => $u->last_login_at?->toIso8601String(),
            ]);

        return Inertia::render('admin/users/Index', ['users' => $users, 'filters' => ['search' => $search]]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/users/Form', $this->formProps());
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $user = User::create($data['user']);
        $user->syncPermissions($data['permissions']);
        ActivityLogger::log('users.created', $user, "إنشاء مستخدم {$user->name}", ['permissions' => $data['permissions']]);

        return redirect()->route('admin.users.index')->with('success', 'تم إنشاء المستخدم.');
    }

    public function edit(User $user): Response
    {
        return Inertia::render('admin/users/Form', $this->formProps() + [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'is_active' => $user->is_active,
                'is_super_admin' => $user->is_super_admin,
                'is_guest' => (bool) $user->is_guest,
                'role_template_id' => $user->role_template_id,
                'hourly_cost' => request()->user()->hasPermission('hr.view_salaries') && $user->hourly_cost !== null ? (float) $user->hourly_cost : null,
                'permissions' => (object) $user->permissionMap(),
            ],
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $this->validated($request, $user);

        if ($user->is($request->user()) && ! ($data['user']['is_active'] ?? true)) {
            return back()->with('error', 'لا يمكنك إيقاف حسابك.');
        }

        $before = $user->permissionMap();
        $user->update($data['user']);
        $user->syncPermissions($data['permissions']);
        ActivityLogger::log('users.updated', $user, "تعديل المستخدم {$user->name}", [
            'fields' => ActivityLogger::diff($user),
            'permissions' => ['from' => $before, 'to' => $data['permissions']],
        ]);

        return redirect()->route('admin.users.index')->with('success', 'تم حفظ التعديلات.');
    }

    public function toggle(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            return back()->with('error', 'لا يمكنك إيقاف حسابك.');
        }
        $user->update(['is_active' => ! $user->is_active]);
        ActivityLogger::log($user->is_active ? 'users.activated' : 'users.deactivated', $user);

        return back()->with('success', $user->is_active ? 'تم تفعيل الحساب.' : 'تم إيقاف الحساب.');
    }

    /** @return array<string, mixed> */
    private function formProps(): array
    {
        return [
            'catalog' => Permissions::catalog(),
            'scopes' => config('permissions.scopes'),
            'templates' => RoleTemplate::orderBy('name')->get(['id', 'name', 'permissions']),
            'canCost' => request()->user()->hasPermission('hr.view_salaries'),
        ];
    }

    /** @return array{user: array<string, mixed>, permissions: array<string, string>} */
    private function validated(Request $request, ?User $user = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user?->id)],
            'phone' => ['nullable', 'string', 'max:32'],
            'password' => [$user ? 'nullable' : 'required', 'confirmed', Password::defaults()],
            'is_active' => ['boolean'],
            'role_template_id' => ['nullable', 'exists:role_templates,id'],
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::in(array_keys(config('permissions.scopes')))],
            'hourly_cost' => ['nullable', 'numeric', 'min:0', 'max:99999'],
        ]);

        $fields = collect($data)->only(['name', 'email', 'phone', 'is_active', 'role_template_id'])->all();
        // The hourly cost is salary data: only people who may see salaries can set it.
        if ($request->user()->hasPermission('hr.view_salaries') && $request->exists('hourly_cost')) {
            $fields['hourly_cost'] = $data['hourly_cost'] ?? null;
        }
        if (! empty($data['password'])) {
            $fields['password'] = $data['password'];
        }

        return ['user' => $fields, 'permissions' => Permissions::sanitize($data['permissions'] ?? [])];
    }
}
