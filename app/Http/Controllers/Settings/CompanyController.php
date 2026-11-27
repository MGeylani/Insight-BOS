<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CompanyController extends Controller
{
    private const FIELDS = ['name', 'legal_name', 'email', 'phone', 'website', 'address', 'tax_number'];

    public function edit(): Response
    {
        $values = [];
        foreach (self::FIELDS as $f) {
            $values[$f] = Setting::get("company.$f", $f === 'name' ? 'Insight Media' : '');
        }

        return Inertia::render('settings/Company', ['company' => $values]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'legal_name' => ['nullable', 'string', 'max:190'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'website' => ['nullable', 'string', 'max:190'],
            'address' => ['nullable', 'string', 'max:500'],
            'tax_number' => ['nullable', 'string', 'max:60'],
        ]);
        foreach ($data as $k => $v) {
            Setting::put("company.$k", $v);
        }
        ActivityLogger::log('settings.company', null, 'تعديل بيانات الشركة', $data);

        return back()->with('success', 'تم حفظ بيانات الشركة.');
    }
}
