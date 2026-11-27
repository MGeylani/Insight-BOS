<?php

namespace App\Http\Controllers;

use App\Models\Currency;
use App\Models\User;
use App\Services\ExchangeRates;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        $rates = Currency::where('is_active', true)->where('is_base', false)->orderBy('code')->get()
            ->map(fn (Currency $c) => ['code' => $c->code, 'name' => $c->name] + (ExchangeRates::rateFor($c->code) ?? ['rate' => null, 'date' => null, 'stale' => true]));

        return Inertia::render('Dashboard', [
            'stats' => [
                'users' => User::where('is_active', true)->count(),
            ],
            'rates' => $rates,
        ]);
    }
}
