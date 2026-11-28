<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\StorageConnection;
use App\Services\ActivityLogger;
use App\Services\Storage\Attachments;
use App\Services\Storage\Drivers\OAuthDriver;
use App\Services\Storage\Providers;
use App\Services\Storage\StorageException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class StorageController extends Controller
{
    public function show(): Response
    {
        $active = StorageConnection::active();
        $creds = $active?->credentials ?? [];

        return Inertia::render('settings/Storage', [
            'providers' => Providers::forUi(),
            'connection' => $active ? [
                ...$active->only('provider', 'status', 'account_label', 'root_folder_name', 'max_upload_mb', 'allowed_types', 'last_error'),
                'connected_at' => $active->connected_at?->toIso8601String(),
                'last_checked_at' => $active->last_checked_at?->toIso8601String(),
                // WebDAV form prefill (never the password).
                'webdav' => $active->provider === 'wd_mycloud' ? ['base_url' => $creds['base_url'] ?? '', 'username' => $creds['username'] ?? ''] : null,
            ] : null,
            'failed' => Attachment::whereIn('status', ['pending', 'failed'])->count(),
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
            'root_folder_name' => ['nullable', 'string', 'max:120'],
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
        // The root folder name only applies before the first upload (folders are remembered afterwards).
        if (! $conn->root_folder_id) {
            $conn->root_folder_name = trim($data['root_folder_name'] ?? '') ?: ($conn->root_folder_name ?: 'Insight BOS');
        }
        if (! $conn->exists) {
            $conn->status = empty($conn->credentials) ? 'disconnected' : 'connected';
        }
        $conn->save();
        ActivityLogger::log('settings.storage', $conn, 'تحديد مزود التخزين: '.Providers::all()[$conn->provider]['label']);

        return back()->with('success', 'تم حفظ إعدادات التخزين.');
    }

    /** Start the OAuth consent screen for the active provider. */
    public function connect(Request $request, string $provider): RedirectResponse|\Symfony\Component\HttpFoundation\Response
    {
        $conn = $this->activeFor($provider);
        $driver = Providers::driver($conn);
        abort_unless($driver instanceof OAuthDriver, 404);
        if (! $driver::configured()) {
            return back()->with('error', 'مفاتيح تطبيق '.Providers::all()[$provider]['label'].' غير موجودة في ملف .env على الخادم.');
        }
        $state = Str::random(40);
        $request->session()->put('storage_oauth', ['state' => $state, 'provider' => $provider]);

        return Inertia::location($driver->authorizeUrl($state, $this->redirectUri($provider)));
    }

    /** The provider sends the admin back here with ?code=…&state=… */
    public function callback(Request $request, string $provider): RedirectResponse
    {
        $expected = $request->session()->pull('storage_oauth');
        if (! $expected || $expected['provider'] !== $provider || ! hash_equals($expected['state'], (string) $request->query('state'))) {
            return redirect('/settings/storage')->with('error', 'انتهت صلاحية طلب الربط أو لا يطابق. أعد المحاولة.');
        }
        if ($request->filled('error') || ! $request->filled('code')) {
            return redirect('/settings/storage')->with('error', 'لم يكتمل الربط: '.($request->query('error_description') ?: $request->query('error', 'أُلغي من صفحة الخدمة')));
        }

        $conn = $this->activeFor($provider);
        /** @var OAuthDriver $driver */
        $driver = Providers::driver($conn);
        try {
            $driver->exchange((string) $request->query('code'), $this->redirectUri($provider));
            $label = $driver->test();
        } catch (StorageException $e) {
            $conn->forceFill(['status' => 'error', 'last_error' => $e->getMessage(), 'last_checked_at' => now()])->save();

            return redirect('/settings/storage')->with('error', $e->getMessage());
        }

        if ($conn->account_label && $conn->account_label !== $label) {
            $this->forgetFolders($conn); // another account: its folders are found or created again
        }
        $this->markConnected($conn, $label, $request->user()->id);
        ActivityLogger::log('settings.storage_connected', $conn, 'ربط '.Providers::all()[$provider]['label'].": $label");

        return redirect('/settings/storage')->with('success', "تم ربط الحساب $label.");
    }

    /** WD My Cloud / WebDAV: address + username + password, checked before saving. */
    public function webdav(Request $request): RedirectResponse
    {
        $conn = $this->activeFor('wd_mycloud');
        $old = $conn->credentials ?? [];
        $data = $request->validate([
            'base_url' => ['required', 'url:http,https', 'max:500'],
            'username' => ['required', 'string', 'max:190'],
            'password' => [empty($old['password']) ? 'required' : 'nullable', 'string', 'max:500'],
        ]);
        $conn->credentials = [
            'base_url' => rtrim($data['base_url'], '/'),
            'username' => $data['username'],
            'password' => $data['password'] ?: ($old['password'] ?? ''),
        ];

        try {
            $label = Providers::driver($conn)->test();
        } catch (StorageException $e) {
            return back()->withErrors(['base_url' => $e->getMessage()]);
        }
        if (($old['base_url'] ?? null) !== $conn->credentials['base_url']) {
            $this->forgetFolders($conn); // another server: folders are created again
        }
        $this->markConnected($conn, $label, $request->user()->id);
        ActivityLogger::log('settings.storage_connected', $conn, "ربط WD My Cloud: $label");

        return back()->with('success', "تم الاتصال بـ $label.");
    }

    public function test(): RedirectResponse
    {
        $conn = StorageConnection::active();
        abort_unless($conn && ! empty($conn->credentials), 422, 'لا يوجد حساب مربوط.');
        try {
            $label = Providers::driver($conn)->test();
        } catch (StorageException $e) {
            $conn->forceFill(['status' => 'error', 'last_error' => $e->getMessage(), 'last_checked_at' => now()])->save();

            return back()->with('error', 'فشل اختبار الاتصال: '.$e->getMessage());
        }
        $conn->forceFill(['status' => 'connected', 'account_label' => $label, 'last_error' => null, 'last_checked_at' => now()])->save();

        return back()->with('success', "الاتصال يعمل ($label).");
    }

    /** Forget the tokens. Files already uploaded stay in the provider. */
    public function disconnect(): RedirectResponse
    {
        $conn = StorageConnection::active();
        abort_unless($conn, 404);
        $conn->forceFill(['credentials' => null, 'status' => 'disconnected', 'account_label' => null, 'last_error' => null])->save();
        $this->forgetFolders($conn); // reconnecting finds the existing folders again by name
        ActivityLogger::log('settings.storage_disconnected', $conn, 'فصل '.Providers::all()[$conn->provider]['label']);

        return back()->with('success', 'تم فصل الحساب. الملفات المرفوعة تبقى في الخدمة.');
    }

    public function retry(): RedirectResponse
    {
        [$ok, $bad] = Attachments::retryFailed(200);

        return back()->with($bad ? 'error' : 'success', "أُعيد رفع $ok ملف".($bad ? "، وما زال $bad يفشل." : '.'));
    }

    private function activeFor(string $provider): StorageConnection
    {
        abort_unless(Providers::exists($provider), 404);
        $conn = StorageConnection::active();
        abort_unless($conn && $conn->provider === $provider, 422, 'اختر هذا المزود واحفظ الإعدادات أولاً.');

        return $conn;
    }

    private function redirectUri(string $provider): string
    {
        return url("/settings/storage/callback/$provider");
    }

    private function markConnected(StorageConnection $conn, string $label, int $userId): void
    {
        $conn->forceFill([
            'status' => 'connected', 'account_label' => $label, 'last_error' => null,
            'connected_by' => $userId, 'connected_at' => now(), 'last_checked_at' => now(),
        ])->save();
    }

    private function forgetFolders(StorageConnection $conn): void
    {
        $conn->forceFill(['root_folder_id' => null])->save();
        $conn->storageFolders()->delete();
    }
}
