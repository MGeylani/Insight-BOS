<?php

namespace Tests\Feature;

use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_without_grant_is_forbidden(): void
    {
        $this->actingAs($this->userWith())->get('/admin/users')->assertForbidden();
        $this->actingAs($this->userWith())->get('/settings/company')->assertForbidden();
    }

    public function test_grant_opens_exactly_that_module(): void
    {
        $user = $this->userWith(['users.view' => 'all']);

        $this->actingAs($user)->get('/admin/users')->assertOk();
        $this->actingAs($user)->get('/admin/users/create')->assertForbidden();
        $this->actingAs($user)->get('/settings/currencies')->assertForbidden();
    }

    public function test_super_admin_passes_every_gate(): void
    {
        $admin = $this->admin();
        foreach (['/admin/users', '/admin/roles', '/admin/activity', '/settings/company', '/settings/currencies', '/settings/storage', '/settings/numbering', '/settings/service-categories'] as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }
    }

    public function test_scope_ranking(): void
    {
        $this->assertTrue(Permissions::scopeCovers('all', 'own'));
        $this->assertTrue(Permissions::scopeCovers('assigned', 'own'));
        $this->assertFalse(Permissions::scopeCovers('own', 'all'));

        $user = $this->userWith(['tasks.edit' => 'own']);
        $this->assertTrue($user->hasPermission('tasks.edit'));
        $this->assertTrue($user->hasPermission('tasks.edit', 'own'));
        $this->assertFalse($user->hasPermission('tasks.edit', 'all'));
    }

    public function test_sanitize_drops_unknown_keys_and_scopes(): void
    {
        $clean = Permissions::sanitize(['crm.view' => 'all', 'crm.fly' => 'all', 'tasks.view' => 'everything']);
        $this->assertSame(['crm.view' => 'all'], $clean);
    }
}
