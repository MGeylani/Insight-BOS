<?php

namespace App\Providers;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\Project;
use App\Models\Quote;
use App\Models\Service;
use App\Models\Task;
use App\Models\TaskList;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Relations\Relation;
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

        // Short, stable names in polymorphic columns (activities, attachments).
        Relation::morphMap([
            'lead' => Lead::class,
            'client' => Client::class,
            'opportunity' => Opportunity::class,
            'quote' => Quote::class,
            'service' => Service::class,
            'contract' => Contract::class,
            'project' => Project::class,
            'task' => Task::class,
            'task_list' => TaskList::class,
        ]);

        Password::defaults(fn () => Password::min(10)->letters()->numbers());
    }
}
