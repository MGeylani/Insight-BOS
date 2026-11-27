<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Services\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Service catalog: everyone with crm.view can browse it; settings.manage edits it. */
class ServiceController extends Controller
{
    public function index(Request $request): Response
    {
        $services = Service::with('category:id,name,tax_rate')->orderBy('sort')->orderBy('name')->get();

        return Inertia::render('crm/services/Index', [
            'services' => $services->map(fn (Service $s) => [
                ...$s->only('id', 'service_category_id', 'name', 'description', 'unit', 'price', 'currency', 'is_active', 'sort'),
                'category' => $s->category?->only('id', 'name', 'tax_rate'),
            ]),
            'categories' => ServiceCategory::orderBy('sort')->orderBy('name')->get(['id', 'name', 'tax_rate', 'is_active']),
            'currencies' => Currency::where('is_active', true)->orderByDesc('is_base')->pluck('code'),
            'units' => config('crm.service_units'),
            'canManage' => $request->user()->hasPermission('settings.manage'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $s = Service::create($this->validated($request) + ['created_by' => $request->user()->id]);
        ActivityLogger::log('crm.service_created', $s, "إضافة خدمة {$s->name}");

        return back()->with('success', 'تمت إضافة الخدمة.');
    }

    public function update(Request $request, Service $service): RedirectResponse
    {
        $service->update($this->validated($request));
        ActivityLogger::log('crm.service_updated', $service, "تعديل خدمة {$service->name}", ActivityLogger::diff($service));

        return back()->with('success', 'تم حفظ الخدمة. الأسعار الجديدة تُطبّق على العروض الجديدة فقط.');
    }

    public function destroy(Service $service): RedirectResponse
    {
        $service->delete();
        ActivityLogger::log('crm.service_archived', $service, "أرشفة خدمة {$service->name}");

        return back()->with('success', 'تمت أرشفة الخدمة.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'service_category_id' => ['required', 'exists:service_categories,id'],
            'name' => ['required', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:5000'],
            'unit' => ['nullable', 'string', 'max:32'],
            'price' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'currency' => ['required', 'exists:currencies,code'],
            'is_active' => ['boolean'],
            'sort' => ['nullable', 'integer', 'min:0'],
        ]);
    }
}
