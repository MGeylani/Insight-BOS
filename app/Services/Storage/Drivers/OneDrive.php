<?php

namespace App\Services\Storage\Drivers;

use App\Services\Storage\StorageException;

/**
 * Microsoft OneDrive / OneDrive for Business (Microsoft Graph). Files are uploaded through an upload
 * session in chunks (any size). Deleting moves the file to the OneDrive recycle bin.
 */
class OneDrive extends OAuthDriver
{
    private const GRAPH = 'https://graph.microsoft.com/v1.0';

    /** Chunk size: a multiple of 320 KiB, under Graph's 60 MiB limit. */
    public static int $chunkBytes = 320 * 1024 * 32; // 10 MiB

    private const SCOPES = 'offline_access Files.ReadWrite User.Read';

    public static function configKey(): string
    {
        return 'onedrive';
    }

    private function tenant(): string
    {
        return config('services.onedrive.tenant') ?: 'common';
    }

    protected function authorizeEndpoint(): string
    {
        return 'https://login.microsoftonline.com/'.$this->tenant().'/oauth2/v2.0/authorize';
    }

    protected function tokenEndpoint(): string
    {
        return 'https://login.microsoftonline.com/'.$this->tenant().'/oauth2/v2.0/token';
    }

    protected function authorizeParams(): array
    {
        return ['scope' => self::SCOPES, 'response_mode' => 'query', 'prompt' => 'select_account'];
    }

    protected function tokenScope(): ?string
    {
        return self::SCOPES;
    }

    public function test(): string
    {
        $r = $this->check($this->call(fn () => $this->api()->get(self::GRAPH.'/me', ['$select' => 'displayName,mail,userPrincipalName'])), 'فشل الاتصال بـ OneDrive');
        $this->check($this->call(fn () => $this->api()->get(self::GRAPH.'/me/drive', ['$select' => 'id'])), 'الحساب لا يملك مساحة OneDrive');

        return $r->json('mail') ?: ($r->json('userPrincipalName') ?: ($r->json('displayName') ?: 'OneDrive'));
    }

    public function rootFolder(): string
    {
        return $this->ensureChild('root', $this->rootName());
    }

    public function ensureChild(string $parentId, string $name): string
    {
        $name = self::cleanName($name);
        $path = self::GRAPH.'/me/drive/items/'.rawurlencode($parentId).':/'.rawurlencode($name);
        $found = $this->call(fn () => $this->api()->get($path, ['$select' => 'id,folder']));
        if ($found->successful()) {
            if (! $found->json('folder')) {
                throw new StorageException("يوجد ملف بالاسم «{$name}» في مكان المجلد.");
            }

            return (string) $found->json('id');
        }
        $this->check($found, 'فشل البحث عن المجلد', [404]);

        $made = $this->check($this->call(fn () => $this->api()->post(self::GRAPH.'/me/drive/items/'.rawurlencode($parentId).'/children', [
            'name' => $name, 'folder' => new \stdClass, '@microsoft.graph.conflictBehavior' => 'fail',
        ])), 'فشل إنشاء المجلد');

        return (string) $made->json('id');
    }

    public function upload(string $localPath, string $name, string $mime, string $folderId): array
    {
        $size = filesize($localPath);
        $name = self::cleanName($name);
        $session = $this->check($this->call(fn () => $this->api()->post(
            self::GRAPH.'/me/drive/items/'.rawurlencode($folderId).':/'.rawurlencode($name).':/createUploadSession',
            ['item' => ['@microsoft.graph.conflictBehavior' => 'rename']]
        )), 'فشل بدء الرفع إلى OneDrive');
        $url = $session->json('uploadUrl');
        if (! $url) {
            throw new StorageException('لم يُرجع OneDrive رابط جلسة الرفع.');
        }

        $handle = fopen($localPath, 'rb');
        $offset = 0;
        $r = null;
        try {
            // The upload URL is pre-authorised: no bearer token on these requests.
            do {
                $chunk = $size > 0 ? fread($handle, self::$chunkBytes) : '';
                $len = strlen($chunk);
                $end = $offset + max($len, 1) - 1;
                $r = $this->check($this->call(fn () => $this->http()
                    ->withHeaders(['Content-Range' => "bytes $offset-$end/$size"])
                    ->withBody($chunk, 'application/octet-stream')->put($url)), 'فشل رفع الملف إلى OneDrive');
                $offset += $len;
            } while ($offset < $size);
        } finally {
            fclose($handle);
        }

        return [
            'external_id' => (string) $r->json('id'),
            'url' => $r->json('webUrl'),
            'name' => (string) ($r->json('name') ?? $name),
            'size' => (int) ($r->json('size') ?? $size),
            'mime' => $r->json('file.mimeType') ?? $mime,
        ];
    }

    public function delete(string $externalId): void
    {
        $this->check($this->call(fn () => $this->api()->delete(self::GRAPH.'/me/drive/items/'.rawurlencode($externalId))),
            'فشل حذف الملف من OneDrive', [404]);
    }
}
