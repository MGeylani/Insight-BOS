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
use Inertia\Inertia;
use Inertia\Response;

class RoleTemplateController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/roles/Index', [
            'templates' => RoleTemplate::orderBy('name')->get()->map(fn (RoleTemplate $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'description' => $t->description,
                'permissions' => (object) $t->permissions,
                'users_count' => User::where('role_template_id', $t->id)->count(),
            ]),
            'catalog' => Permissions::catalog(),
            'scopes' => config('permissions.scopes'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $t = RoleTemplate::create($this->validated($request));
        ActivityLogger::log('roles.created', $t, "إنشاء قالب {$t->name}");

        return back()->with('success', 'تم إنشاء القالب.');
    }

    public function update(Request $request, RoleTemplate $roleTemplate): RedirectResponse
    {
        $roleTemplate->update($this->validated($request));
        ActivityLogger::log('roles.updated', $roleTemplate, "تعديل قالب {$roleTemplate->name}");

        // Editing a template does not change users who already have it: their grants are their own.
        return back()->with('success', 'تم حفظ القالب. صلاحيات المستخدمين الحاليين لا تتغير تلقائياً.');
    }

    public function destroy(RoleTemplate $roleTemplate): RedirectResponse
    {
        User::where('role_template_id', $roleTemplate->id)->update(['role_template_id' => null]);
        ActivityLogger::log('roles.deleted', null, "حذف قالب {$roleTemplate->name}");
        $roleTemplate->delete();

        return back()->with('success', 'تم حذف القالب.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::in(array_keys(config('permissions.scopes')))],
        ]);
        $data['permissions'] = Permissions::sanitize($data['permissions'] ?? []);

        return $data;
    }
}
