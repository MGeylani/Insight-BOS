<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /**
     * @param  array<string, string>  $permissions
     */
    protected function userWith(array $permissions = []): User
    {
        $user = User::factory()->create();
        $user->syncPermissions($permissions);

        return $user;
    }

    protected function admin(): User
    {
        $u = User::factory()->create();
        $u->forceFill(['is_super_admin' => true])->save();

        return $u;
    }
}
