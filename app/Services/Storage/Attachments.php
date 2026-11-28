<?php

namespace App\Services\Storage;

use App\Models\Attachment;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Project;
use App\Models\Quote;
use App\Models\Task;
use App\Models\StorageConnection;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The only place that uploads, retries and deletes attachments.
 *
 * Upload: the file is kept in a temporary local file, an attachment row is created (pending) and the
 * upload is tried at once. On success the temporary file is removed (BOS never keeps files); on failure
 * the row is marked failed with the error, and `storage:retry` (scheduler) or the retry button tries again.
 */
class Attachments
{
    /** Record types that take attachments: morph key => [model, permission module]. */
    public const TYPES = [
        'client' => [Client::class, 'crm'],
        'project' => [Project::class, 'projects'],
        'contract' => [Contract::class, 'crm'],
        'quote' => [Quote::class, 'crm'],
        'task' => [Task::class, 'tasks'],
    ];

    private const TEMP_DIR = 'attachment-uploads';

    public static function resolve(string $type, int $id): Model
    {
        $class = self::TYPES[$type][0] ?? abort(404);

        return $class::findOrFail($id);
    }

    public static function can(User $user, Model $owner, string $action): bool
    {
        $module = self::TYPES[$owner->getMorphClass()][1] ?? null;

        return $module !== null && $user->hasPermission("$module.$action") && $owner->isVisibleTo($user, "$module.$action");
    }

    /** Upload rules for the UI and validation. @return array<string, mixed> */
    public static function policy(): array
    {
        $c = StorageConnection::active();

        return [
            'connected' => $c?->status === 'connected',
            'provider' => $c ? (Providers::all()[$c->provider]['label'] ?? $c->provider) : null,
            'max_mb' => (int) ($c->max_upload_mb ?? 100),
            'types' => array_values($c->allowed_types ?? []),
        ];
    }

    /** Everything the attachments panel needs for one record. @return array<string, mixed> */
    public static function payload(Model $owner, User $user): array
    {
        return [
            'type' => $owner->getMorphClass(),
            'id' => $owner->getKey(),
            'items' => self::listFor($owner, $user),
            'can_upload' => self::can($user, $owner, 'edit'),
            'policy' => self::policy(),
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function listFor(Model $owner, User $user): array
    {
        $canEdit = self::can($user, $owner, 'edit');

        return Attachment::with('uploader:id,name')
            ->where('attachable_type', $owner->getMorphClass())->where('attachable_id', $owner->getKey())
            ->latest('id')->get()
            ->map(fn (Attachment $a) => [
                ...$a->only('id', 'name', 'size', 'mime', 'status', 'provider', 'attempts', 'version'),
                'proofable' => $owner->getMorphClass() === 'task' && $a->isProofable(),
                'error' => $a->status === 'failed' ? $a->error : null,
                'uploader' => $a->uploader?->name,
                'created_at' => $a->created_at?->toIso8601String(),
                'can_delete' => $canEdit,
                'can_retry' => $canEdit && $a->status !== 'uploaded',
            ])->all();
    }

    public static function store(Model $owner, UploadedFile $file, User $user): Attachment
    {
        $c = StorageManager::connected();
        if (! $c) {
            throw ValidationException::withMessages(['file' => 'لا توجد خدمة تخزين مربوطة. اربطها من الإعدادات ← التخزين السحابي.']);
        }
        $policy = self::policy();
        $ext = strtolower($file->getClientOriginalExtension());
        if ($policy['types'] && ! in_array($ext, $policy['types'], true)) {
            throw ValidationException::withMessages(['file' => 'نوع الملف غير مسموح. المسموح: '.implode('، ', $policy['types'])]);
        }
        if ($file->getSize() > $policy['max_mb'] * 1024 * 1024) {
            throw ValidationException::withMessages(['file' => "حجم الملف أكبر من الحد المسموح ({$policy['max_mb']} ميغابايت)."]);
        }

        $temp = Storage::disk('local')->putFileAs(self::TEMP_DIR, $file, (string) Str::uuid());
        if ($temp === false) {
            throw ValidationException::withMessages(['file' => 'تعذّر استلام الملف على الخادم.']);
        }
        $a = Attachment::create([
            'attachable_type' => $owner->getMorphClass(),
            'attachable_id' => $owner->getKey(),
            'storage_connection_id' => $c->id,
            'provider' => $c->provider,
            'name' => mb_substr($file->getClientOriginalName() ?: 'file', 0, 190),
            'size' => (int) $file->getSize(),
            'mime' => $file->getClientMimeType() ?: 'application/octet-stream',
            'temp_path' => $temp,
            'uploaded_by' => $user->id,
        ]);
        $a->status = 'pending';
        ActivityLogger::log('files.uploaded', $owner, "رفع الملف: {$a->name}");
        self::attempt($a);

        return $a;
    }

    /** Try (again) to send a pending or failed attachment to its provider. */
    public static function attempt(Attachment $a): bool
    {
        if ($a->isUploaded()) {
            return true;
        }
        $c = $a->storageConnection;
        $owner = $a->attachable;
        $path = $a->temp_path ? Storage::disk('local')->path($a->temp_path) : null;

        try {
            if (! $owner) {
                throw new StorageException('السجل المرتبط بالملف لم يعد موجوداً.');
            }
            if (! $path || ! is_file($path)) {
                throw new StorageException('النسخة المؤقتة من الملف لم تعد موجودة على الخادم؛ ارفعه من جديد.');
            }
            if (! $c || $c->status !== 'connected') {
                throw new StorageException('خدمة التخزين غير مربوطة حالياً.');
            }
            $folder = StorageManager::folderFor($c, $owner);
            $result = Providers::driver($c)->upload($path, $a->name, $a->mime ?: 'application/octet-stream', $folder);
        } catch (StorageException $e) {
            if ($e->status === 404 && $c && $owner) {
                StorageManager::forget($c, $owner); // a folder was removed in the provider: recreate next time
            }
            $a->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 2000), 'attempts' => $a->attempts + 1])->save();

            return false;
        }

        $temp = $a->temp_path;
        $a->forceFill([
            'status' => 'uploaded', 'error' => null, 'attempts' => $a->attempts + 1, 'uploaded_at' => now(),
            'external_id' => $result['external_id'], 'url' => $result['url'], 'size' => $result['size'] ?: $a->size,
            'mime' => $result['mime'] ?: $a->mime, 'temp_path' => null,
        ])->save();
        Storage::disk('local')->delete($temp);

        return true;
    }

    /** Remove the attachment: from the provider when it was uploaded there, and from BOS. */
    public static function delete(Attachment $a): void
    {
        if ($a->isUploaded() && $a->external_id) {
            $c = $a->storageConnection;
            if ($c && ! empty($c->credentials) && $c->status === 'connected') {
                try {
                    Providers::driver($c)->delete($a->external_id);
                } catch (StorageException $e) {
                    throw ValidationException::withMessages(['file' => 'تعذّر حذف الملف من خدمة التخزين: '.$e->getMessage()]);
                }
            }
        }
        if ($a->temp_path) {
            Storage::disk('local')->delete($a->temp_path);
        }
        $a->forceFill(['temp_path' => null])->save();
        $a->delete();
        if ($a->attachable) {
            ActivityLogger::log('files.deleted', $a->attachable, "حذف الملف: {$a->name}");
        }
    }

    /** Retry failed uploads (scheduler). @return array{0: int, 1: int} [uploaded, still failing] */
    public static function retryFailed(int $limit = 50): array
    {
        $ok = 0;
        $bad = 0;
        Attachment::whereIn('status', ['pending', 'failed'])->where('attempts', '<', Attachment::MAX_AUTO_ATTEMPTS)
            ->where('created_at', '<', now()->subMinute())->orderBy('id')->limit($limit)->get()
            ->each(function (Attachment $a) use (&$ok, &$bad) {
                self::attempt($a) ? $ok++ : $bad++;
            });

        return [$ok, $bad];
    }
}
