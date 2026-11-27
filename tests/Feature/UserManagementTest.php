<?php

namespace Tests\Feature;

use App\Models\RoleTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_creates_user_with_direct_permissions(): void
    {
        $manager = $this->userWith(['users.view' => 'all', 'users.manage' => 'all']);

        $this->actingAs($manager)->post('/admin/users', [
            'name' => 'ليلى',
            'email' => 'layla@insightpromedia.com',
            'password' => 'Secret12345',
            'password_confirmation' => 'Secret12345',
            'is_active' => true,
            'permissions' => ['crm.view' => 'all', 'tasks.edit' => 'own', 'bogus.key' => 'all'],
        ])->assertRedirect('/admin/users');

        $u = User::where('email', 'layla@insightpromedia.com')->firstOrFail();
        $this->assertSame(['crm.view' => 'all', 'tasks.edit' => 'own'], collect($u->permissionMap())->sortKeys()->all());
    }

    public function test_template_change_does_not_alter_existing_users(): void
    {
        $admin = $this->admin();
        $tpl = RoleTemplate::create(['name' => 'مبيعات', 'permissions' => ['crm.view' => 'all']]);
        $user = $this->userWith(['crm.view' => 'all']);
        $user->update(['role_template_id' => $tpl->id]);

        $this->actingAs($admin)->put("/admin/roles/{$tpl->id}", [
            'name' => 'مبيعات', 'permissions' => ['crm.view' => 'all', 'crm.edit' => 'all'],
        ])->assertRedirect();

        $this->assertFalse($user->fresh()->hasPermission('crm.edit'));
    }

    public function test_user_cannot_deactivate_self(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post("/admin/users/{$admin->id}/toggle")->assertSessionHas('error');
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_toggle_deactivates_other_user(): void
    {
        $admin = $this->admin();
        $other = User::factory()->create();

        $this->actingAs($admin)->post("/admin/users/{$other->id}/toggle");
        $this->assertFalse($other->fresh()->is_active);
    }
}
