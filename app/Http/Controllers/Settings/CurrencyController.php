<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Services\ActivityLogger;
use App\Services\ExchangeRates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class CurrencyController extends Controller
{
    public function index(): Response
    {
        $currencies = Currency::orderByDesc('is_base')->orderBy('code')->get();
        $since = now()->subDays(30)->toDateString();

        $history = ExchangeRate::with('enteredBy:id,name')
            ->whereDate('date', '>=', $since)
            ->orderByDesc('date')->get()
            ->groupBy(fn (ExchangeRate $r) => $r->date->toDateString())
            ->map(fn ($rows, $date) => [
                'date' => $date,
                'rates' => $rows->mapWithKeys(fn ($r) => [$r->currency_code => (float) $r->rate]),
                'by' => $rows->first()->enteredBy?->name,
            ])->values();

        return Inertia::render('settings/Currencies', [
            'currencies' => $currencies,
            'current' => $currencies->where('is_base', false)->mapWithKeys(fn ($c) => [$c->code => ExchangeRates::rateFor($c->code)]),
            'history' => $history,
            'today' => now()->toDateString(),
        ]);
    }

    public function storeRates(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date', 'before_or_equal:today'],
            'rates' => ['required', 'array'],
            'rates.*' => ['nullable', 'numeric', 'gt:0'],
        ]);

        $codes = Currency::where('is_base', false)->pluck('code')->all();
        $saved = [];
        foreach ($data['rates'] as $code => $rate) {
            if ($rate === null || $rate === '' || ! in_array($code, $codes, true)) {
                continue;
            }
            ExchangeRate::updateOrCreate(
                ['currency_code' => $code, 'date' => Carbon::parse($data['date'])->toDateString()],
                ['rate' => $rate, 'entered_by' => $request->user()->id],
            );
            $saved[$code] = (float) $rate;
        }

        if (! $saved) {
            return back()->with('error', 'أدخل سعراً واحداً على الأقل.');
        }
        ActivityLogger::log('settings.exchange_rates', null, 'إدخال أسعار صرف '.$data['date'], $saved);

        return back()->with('success', 'تم حفظ أسعار الصرف.');
    }

    public function update(Request $request, Currency $currency): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'symbol' => ['required', 'string', 'max:8'],
            'decimals' => ['required', 'integer', 'between:0,4'],
            'is_active' => ['boolean'],
        ]);
        if ($currency->is_base) {
            $data['is_active'] = true;
        }
        $currency->update($data);
        ActivityLogger::log('settings.currency', $currency, "تعديل العملة {$currency->code}", ActivityLogger::diff($currency));

        return back()->with('success', 'تم حفظ العملة.');
    }
}
