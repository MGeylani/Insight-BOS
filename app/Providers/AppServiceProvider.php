<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Permissions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Every catalog permission ("module.action") is usable with Gate / can: middleware.
        Gate::before(function (User $user, string $ability) {
            if (Permissions::exists($ability)) {
                return $user->hasPermission($ability);
            }

            return $user->is_super_admin ? true : null;
        });

        Password::defaults(fn () => Password::min(10)->letters()->numbers());
    }
}
