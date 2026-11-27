<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\ExchangeRate;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class ExchangeRates
{
    /**
     * Rate for a currency on a date: 1 base unit (USD) = rate units of $code.
     * Falls back to the latest earlier rate; `stale` tells the caller to warn the manager.
     *
     * @return array{rate: float, date: string|null, stale: bool}|null
     */
    public static function rateFor(string $code, ?CarbonInterface $date = null): ?array
    {
        $date = Carbon::parse($date ?? now())->startOfDay();

        if (Currency::whereKey($code)->value('is_base')) {
            return ['rate' => 1.0, 'date' => $date->toDateString(), 'stale' => false];
        }

        $row = ExchangeRate::where('currency_code', $code)
            ->whereDate('date', '<=', $date)
            ->orderByDesc('date')
            ->first();

        if (! $row) {
            return null;
        }

        return [
            'rate' => (float) $row->rate,
            'date' => $row->date->toDateString(),
            'stale' => ! $row->date->isSameDay($date),
        ];
    }

    public static function toBase(float $amount, string $code, ?CarbonInterface $date = null): ?float
    {
        $r = self::rateFor($code, $date);

        return $r ? round($amount / $r['rate'], 6) : null;
    }

    /**
     * Active non-base currencies that have no rate entered for today.
     *
     * @return list<string>
     */
    public static function missingToday(): array
    {
        $today = now()->toDateString();

        return Currency::where('is_active', true)->where('is_base', false)
            ->whereDoesntHave('rates', fn ($q) => $q->whereDate('date', $today))
            ->pluck('code')->all();
    }
}
