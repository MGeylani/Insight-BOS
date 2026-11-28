<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\ActivityLogger;
use App\Services\Sales\Contracts;
use App\Services\Sales\Quotes;
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

        return Inertia::render('settings/Company', [
            'company' => $values,
            'quotes' => ['validity_days' => Quotes::validityDays(), 'default_terms' => Quotes::defaultTerms() ?? ''],
            'invoices' => [
                'payment_days' => \App\Services\Finance\Invoices::paymentDays(),
                'default_terms' => \App\Services\Finance\Invoices::defaultTerms() ?? '',
                'payment_details' => \App\Services\Finance\Invoices::defaultPaymentDetails() ?? '',
                'retainer_lead_days' => (int) (Setting::get('invoices.retainer_lead_days') ?: config('finance.retainer_lead_days', 7)),
            ],
            'contracts' => ['terms_project' => Contracts::defaultTerms('project') ?? '', 'terms_retainer' => Contracts::defaultTerms('retainer') ?? ''],
        ]);
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

    /** Defaults for new quotes: validity period and terms text. */
    public function updateQuotes(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'validity_days' => ['required', 'integer', 'between:1,365'],
            'default_terms' => ['nullable', 'string', 'max:10000'],
        ]);
        Setting::put('quotes.validity_days', $data['validity_days']);
        Setting::put('quotes.default_terms', $data['default_terms'] ?? '');
        ActivityLogger::log('settings.quotes', null, 'تعديل إعدادات عروض الأسعار', $data);

        return back()->with('success', 'تم حفظ إعدادات عروض الأسعار.');
    }

    /** Invoice defaults: payment days, terms, bank details, retainer lead days. */
    public function updateInvoices(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'payment_days' => ['required', 'integer', 'between:0,365'],
            'default_terms' => ['nullable', 'string', 'max:10000'],
            'payment_details' => ['nullable', 'string', 'max:5000'],
            'retainer_lead_days' => ['required', 'integer', 'between:0,60'],
        ]);
        foreach ($data as $k => $v) {
            Setting::put("invoices.$k", $v ?? '');
        }
        ActivityLogger::log('settings.invoices', null, 'تعديل إعدادات الفواتير');

        return back()->with('success', 'تم حفظ إعدادات الفواتير.');
    }

    /** Default terms text for new contracts, one per contract type. */
    public function updateContracts(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'terms_project' => ['nullable', 'string', 'max:20000'],
            'terms_retainer' => ['nullable', 'string', 'max:20000'],
        ]);
        Setting::put('contracts.terms_project', $data['terms_project'] ?? '');
        Setting::put('contracts.terms_retainer', $data['terms_retainer'] ?? '');
        ActivityLogger::log('settings.contracts', null, 'تعديل الشروط الافتراضية للعقود');

        return back()->with('success', 'تم حفظ إعدادات العقود.');
    }
}
