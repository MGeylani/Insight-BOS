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
                ] : null,
                'permissions' => $user ? ($user->is_super_admin ? ['*' => 'all'] : $user->permissionMap()) : [],
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'alerts' => fn () => [
                'missing_rates' => $user && $user->hasPermission('settings.exchange_rates')
                    ? ExchangeRates::missingToday() : [],
            ],
        ];
    }
}
