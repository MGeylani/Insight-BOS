<?php

namespace App\Services\Storage\Drivers;

use App\Services\Storage\StorageException;
use GuzzleHttp\Psr7\Utils;

/**
 * Google Drive (Drive API v3). Scope `drive.file`: BOS only sees the folders and files it created,
 * never the rest of the company Drive. Shared drives are supported (supportsAllDrives).
 * Deleting moves the file to the Drive trash.
 */
class GoogleDrive extends OAuthDriver
{
    private const API = 'https://www.googleapis.com/drive/v3';

    private const UPLOAD = 'https://www.googleapis.com/upload/drive/v3/files';

    private const FOLDER = 'application/vnd.google-apps.folder';

    public static function configKey(): string
    {
        return 'google_drive';
    }

    protected function authorizeEndpoint(): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth';
    }

    protected function tokenEndpoint(): string
    {
        return 'https://oauth2.googleapis.com/token';
    }

    protected function authorizeParams(): array
    {
        return [
            'scope' => 'https://www.googleapis.com/auth/drive.file',
            'access_type' => 'offline',
            'prompt' => 'consent', // always return a refresh token
            'include_granted_scopes' => 'true',
        ];
    }

    public function test(): string
    {
        $r = $this->check($this->call(fn () => $this->api()->get(self::API.'/about', ['fields' => 'user(displayName,emailAddress)'])), 'فشل الاتصال بـ Google Drive');

        return $r->json('user.emailAddress') ?: ($r->json('user.displayName') ?: 'Google Drive');
    }

    public function rootFolder(): string
    {
        return $this->ensureChild('root', $this->rootName());
    }

    public function ensureChild(string $parentId, string $name): string
    {
        $name = self::cleanName($name);
        $q = sprintf("name = '%s' and '%s' in parents and mimeType = '%s' and trashed = false",
            str_replace(['\\', "'"], ['\\\\', "\\'"], $name), $parentId, self::FOLDER);
        $found = $this->check($this->call(fn () => $this->api()->get(self::API.'/files', [
            'q' => $q, 'fields' => 'files(id)', 'pageSize' => 1,
            'supportsAllDrives' => 'true', 'includeItemsFromAllDrives' => 'true',
        ])), 'فشل البحث عن المجلد');
        if ($id = $found->json('files.0.id')) {
            return $id;
        }

        $made = $this->check($this->call(fn () => $this->api()->post(self::API.'/files?supportsAllDrives=true&fields=id', [
            'name' => $name, 'mimeType' => self::FOLDER, 'parents' => [$parentId],
        ])), 'فشل إنشاء المجلد');

        return (string) $made->json('id');
    }

    /** Resumable upload: one request for the metadata, one for the bytes. */
    public function upload(string $localPath, string $name, string $mime, string $folderId): array
    {
        $size = filesize($localPath);
        $init = $this->check($this->call(fn () => $this->api()
            ->withHeaders(['X-Upload-Content-Type' => $mime, 'X-Upload-Content-Length' => (string) $size])
            ->post(self::UPLOAD.'?uploadType=resumable&supportsAllDrives=true&fields=id,name,size,mimeType,webViewLink', [
                'name' => self::cleanName($name), 'parents' => [$folderId],
            ])), 'فشل بدء الرفع إلى Google Drive');
        $session = $init->header('Location');
        if (! $session) {
            throw new StorageException('لم تُرجع Google Drive رابط جلسة الرفع.');
        }

        // The stream closes its file handle when it is released.
        $body = Utils::streamFor(fopen($localPath, 'rb'));
        $r = $this->check($this->call(fn () => $this->http()->withToken($this->token())
            ->withBody($body, $mime)->put($session)), 'فشل رفع الملف إلى Google Drive');

        return [
            'external_id' => (string) $r->json('id'),
            'url' => $r->json('webViewLink') ?: 'https://drive.google.com/file/d/'.$r->json('id').'/view',
            'name' => (string) ($r->json('name') ?? $name),
            'size' => (int) ($r->json('size') ?? $size),
            'mime' => $r->json('mimeType') ?? $mime,
        ];
    }

    public function delete(string $externalId): void
    {
        $this->check($this->call(fn () => $this->api()->patch(self::API.'/files/'.rawurlencode($externalId).'?supportsAllDrives=true', ['trashed' => true])),
            'فشل حذف الملف من Google Drive', [404]);
    }

    /** File content (streamed), for showing pasted images inside BOS. */
    public function read(string $externalId): \Illuminate\Http\Client\Response
    {
        $r = $this->call(fn () => $this->api()->accept('*/*')->withOptions(['stream' => true])
            ->get(self::API.'/files/'.rawurlencode($externalId).'?alt=media&supportsAllDrives=true'));

        return $this->check($r, 'فشل قراءة الملف من Google Drive');
    }
}
