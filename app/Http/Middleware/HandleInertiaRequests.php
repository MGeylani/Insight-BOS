<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use App\Services\ExchangeRates;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'company' => fn () => [
                'name' => Setting::get('company.name', 'Insight Media'),
            ],
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'is_super_admin' => $user->is_super_admin,
                    'is_guest' => (bool) $user->is_guest,
                ] : null,
                'permissions' => $user ? ($user->is_super_admin ? ['*' => 'all'] : $user->permissionMap()) : [],
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'notifications_unread' => fn () => $user ? $user->unreadNotifications()->count() : 0,
            // The running timer, shown in the header on every page.
            'timer' => function () use ($user) {
                if (! $user || ! $user->hasPermission('tasks.track_time')) {
                    return null;
                }
                $e = \App\Services\Tasks\TimeTracking::running($user);
                $task = $e?->task;

                return $e ? [
                    'id' => $e->id, 'started_at' => $e->started_at->toIso8601String(),
                    'task' => $task ? ['id' => $task->id, 'code' => $task->code(), 'title' => $task->title] : null,
                ] : null;
            },
            'alerts' => fn () => [
                'missing_rates' => $user && $user->hasPermission('settings.exchange_rates')
                    ? ExchangeRates::missingToday() : [],
            ],
        ];
    }
}
