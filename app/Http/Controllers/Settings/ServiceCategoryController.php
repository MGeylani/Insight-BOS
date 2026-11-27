<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\ServiceCategory;
use App\Services\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ServiceCategoryController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('settings/ServiceCategories', [
            'categories' => ServiceCategory::orderBy('sort')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $c = ServiceCategory::create($this->validated($request));
        ActivityLogger::log('settings.service_category', $c, "إضافة تصنيف {$c->name}");

        return back()->with('success', 'تمت إضافة التصنيف.');
    }

    public function update(Request $request, ServiceCategory $serviceCategory): RedirectResponse
    {
        $serviceCategory->update($this->validated($request));
        ActivityLogger::log('settings.service_category', $serviceCategory, "تعديل تصنيف {$serviceCategory->name}", ActivityLogger::diff($serviceCategory));

        return back()->with('success', 'تم حفظ التصنيف. النسبة الجديدة تُطبّق على المستندات الجديدة فقط.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'tax_rate' => ['required', 'numeric', 'between:0,100'],
            'is_active' => ['boolean'],
            'sort' => ['nullable', 'integer', 'min:0'],
        ]);
    }
}
