<?php

namespace App\Services\Storage;

use App\Models\StorageConnection;
use App\Services\Storage\Drivers\Dropbox;
use App\Services\Storage\Drivers\GoogleDrive;
use App\Services\Storage\Drivers\OAuthDriver;
use App\Services\Storage\Drivers\OneDrive;
use App\Services\Storage\Drivers\WebDav;

class Providers
{
    /**
     * Supported providers. `auth` is how the admin connects:
     *  - oauth: sign in once with the company account (button in settings)
     *  - webdav: server address + username + password (WD My Cloud remote access)
     *
     * @return array<string, array{label: string, auth: string, note: string, driver: class-string<StorageProvider>}>
     */
    public static function all(): array
    {
        return [
            'google_drive' => [
                'label' => 'Google Drive',
                'auth' => 'oauth',
                'note' => 'يُفضّل ربط Shared Drive باسم الشركة لا حساباً شخصياً.',
                'driver' => GoogleDrive::class,
            ],
            'onedrive' => [
                'label' => 'Microsoft OneDrive',
                'auth' => 'oauth',
                'note' => 'يُربط بحساب Microsoft 365 الخاص بالشركة.',
                'driver' => OneDrive::class,
            ],
            'dropbox' => [
                'label' => 'Dropbox',
                'auth' => 'oauth',
                'note' => 'يُربط بحساب Dropbox Business أو حساب الشركة.',
                'driver' => Dropbox::class,
            ],
            'wd_mycloud' => [
                'label' => 'WD My Cloud',
                'auth' => 'webdav',
                'note' => 'يتطلب تفعيل الوصول عن بُعد على الجهاز، ويتوقف إذا انقطع إنترنت المكتب.',
                'driver' => WebDav::class,
            ],
        ];
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    public static function driver(StorageConnection $connection): StorageProvider
    {
        $class = self::all()[$connection->provider]['driver'] ?? null;
        if (! $class) {
            throw new StorageException("مزود تخزين غير معروف: {$connection->provider}");
        }

        return new $class($connection);
    }

    /** OAuth providers need app keys in .env before the "connect" button works. */
    public static function configured(string $key): bool
    {
        $class = self::all()[$key]['driver'] ?? null;

        return $class && (! is_subclass_of($class, OAuthDriver::class) || $class::configured());
    }

    /** Settings payload (no driver class names). @return array<string, array<string, mixed>> */
    public static function forUi(): array
    {
        return collect(self::all())->map(fn ($p, $k) => [
            'label' => $p['label'], 'auth' => $p['auth'], 'note' => $p['note'],
            'configured' => self::configured($k),
            'env' => match ($k) {
                'google_drive' => ['GOOGLE_DRIVE_CLIENT_ID', 'GOOGLE_DRIVE_CLIENT_SECRET'],
                'onedrive' => ['ONEDRIVE_CLIENT_ID', 'ONEDRIVE_CLIENT_SECRET', 'ONEDRIVE_TENANT'],
                'dropbox' => ['DROPBOX_APP_KEY', 'DROPBOX_APP_SECRET'],
                default => [],
            },
            'redirect_uri' => $p['auth'] === 'oauth' ? url("/settings/storage/callback/$k") : null,
        ])->all();
    }
}
