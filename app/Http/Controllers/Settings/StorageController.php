<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\StorageConnection;
use App\Services\ActivityLogger;
use App\Services\Storage\Providers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class StorageController extends Controller
{
    public function show(): Response
    {
        $active = StorageConnection::active();

        return Inertia::render('settings/Storage', [
            'providers' => Providers::all(),
            'connection' => $active ? $active->only(
                'provider', 'status', 'account_label', 'root_folder_name', 'max_upload_mb', 'allowed_types', 'connected_at', 'last_error'
            ) : null,
        ]);
    }

    /**
     * Choose the active provider and upload policy. Exactly one provider is active;
     * switching providers does not move files already stored elsewhere.
     */
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'provider' => ['required', Rule::in(array_keys(Providers::all()))],
            'max_upload_mb' => ['required', 'integer', 'between:1,2048'],
            'allowed_types' => ['nullable', 'array'],
            'allowed_types.*' => ['string', 'max:10', 'regex:/^[a-z0-9]+$/'],
        ]);

        $current = StorageConnection::active();
        if ($current && $current->provider !== $data['provider']) {
            $current->update(['is_active' => false]);
            $current = null;
        }

        $conn = $current ?? StorageConnection::firstOrNew(['provider' => $data['provider']]);
        $conn->fill([
            'max_upload_mb' => $data['max_upload_mb'],
            'allowed_types' => $data['allowed_types'] ?? null,
            'is_active' => true,
        ]);
        if (! $conn->exists) {
            $conn->status = 'disconnected';
        }
        $conn->save();
        ActivityLogger::log('settings.storage', $conn, 'تحديد مزود التخزين: '.Providers::all()[$conn->provider]['label']);

        return back()->with('success', 'تم حفظ إعدادات التخزين.');
    }
}
