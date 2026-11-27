<?php

namespace App\Services\Storage;

class Providers
{
    /**
     * Supported providers. `auth` is how the admin connects:
     *  - oauth: sign in once with the company account (button in settings)
     *  - webdav: server address + username + password (WD My Cloud remote access)
     *
     * @return array<string, array{label: string, auth: string, note: string}>
     */
    public static function all(): array
    {
        return [
            'google_drive' => [
                'label' => 'Google Drive',
                'auth' => 'oauth',
                'note' => 'يُفضّل ربط Shared Drive باسم الشركة لا حساباً شخصياً.',
            ],
            'onedrive' => [
                'label' => 'Microsoft OneDrive',
                'auth' => 'oauth',
                'note' => 'يُربط بحساب Microsoft 365 الخاص بالشركة.',
            ],
            'dropbox' => [
                'label' => 'Dropbox',
                'auth' => 'oauth',
                'note' => 'يُربط بحساب Dropbox Business أو حساب الشركة.',
            ],
            'wd_mycloud' => [
                'label' => 'WD My Cloud',
                'auth' => 'webdav',
                'note' => 'يتطلب تفعيل الوصول عن بُعد على الجهاز، ويتوقف إذا انقطع إنترنت المكتب.',
            ],
        ];
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::all());
    }
}
