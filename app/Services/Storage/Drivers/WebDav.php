<?php

namespace App\Services\Storage\Drivers;

use App\Services\Storage\StorageException;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * WebDAV (WD My Cloud with remote access, or any WebDAV server). Folders and files are addressed by
 * their path under the base URL. credentials: {base_url, username, password}.
 * There is no web viewer: BOS streams the file to the browser (AttachmentController::open).
 */
class WebDav extends Driver
{
    private function base(): string
    {
        $url = rtrim((string) ($this->connection->credentials['base_url'] ?? ''), '/');
        if ($url === '') {
            throw new StorageException('لم يُحدَّد عنوان خادم WebDAV.');
        }

        return $url;
    }

    private function dav(): PendingRequest
    {
        $c = $this->connection->credentials ?? [];

        return $this->http()->withBasicAuth((string) ($c['username'] ?? ''), (string) ($c['password'] ?? ''))->withHeaders(['Accept' => '*/*']);
    }

    /** Full URL of a path like "/Insight BOS/العملاء", each segment encoded. */
    public function url(string $path): string
    {
        $segments = array_filter(explode('/', $path), 'strlen');

        return $this->base().($segments ? '/'.implode('/', array_map('rawurlencode', $segments)) : '/');
    }

    private function send(string $method, string $path, array $options = []): Response
    {
        return $this->call(fn () => $this->dav()->send($method, $this->url($path), $options));
    }

    public function test(): string
    {
        $r = $this->send('PROPFIND', '/', ['headers' => ['Depth' => '0']]);
        if ($r->status() === 401) {
            throw new StorageException('اسم المستخدم أو كلمة المرور غير صحيحة (401).', 401);
        }
        $this->check($r, 'فشل الاتصال بخادم WebDAV');

        return ($this->connection->credentials['username'] ?? '').'@'.(parse_url($this->base(), PHP_URL_HOST) ?: 'webdav');
    }

    public function rootFolder(): string
    {
        return $this->ensureChild('', $this->rootName());
    }

    public function ensureChild(string $parentId, string $name): string
    {
        $path = rtrim($parentId, '/').'/'.self::cleanName($name);
        // 201 created, 405 already exists.
        $this->check($this->send('MKCOL', $path), 'فشل إنشاء المجلد', [405]);

        return $path;
    }

    public function upload(string $localPath, string $name, string $mime, string $folderId): array
    {
        $path = $this->freePath(rtrim($folderId, '/'), self::cleanName($name));
        $body = Utils::streamFor(fopen($localPath, 'rb')); // closes its handle when released
        $this->check($this->call(fn () => $this->dav()->withBody($body, $mime)->put($this->url($path))), 'فشل رفع الملف إلى WebDAV');

        return ['external_id' => $path, 'url' => null, 'name' => basename($path), 'size' => (int) filesize($localPath), 'mime' => $mime];
    }

    public function delete(string $externalId): void
    {
        $this->check($this->send('DELETE', $externalId), 'فشل حذف الملف من WebDAV', [404]);
    }

    /** Stream a file back (for the "open" button). */
    public function read(string $externalId): Response
    {
        $r = $this->call(fn () => $this->dav()->withOptions(['stream' => true])->get($this->url($externalId)));

        return $this->check($r, 'فشل قراءة الملف من WebDAV');
    }

    /** "name.pdf", or "name (2).pdf" … when the name is taken. PUT would overwrite silently. */
    private function freePath(string $folder, string $name): string
    {
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $stem = $ext === '' ? $name : mb_substr($name, 0, -mb_strlen($ext) - 1);
        for ($i = 1; $i <= 50; $i++) {
            $candidate = $folder.'/'.($i === 1 ? $name : "$stem ($i)".($ext === '' ? '' : ".$ext"));
            $head = $this->send('HEAD', $candidate);
            if ($head->status() === 404) {
                return $candidate;
            }
            $this->check($head, 'فشل التحقق من اسم الملف');
        }
        throw new StorageException('يوجد عدد كبير من الملفات بالاسم نفسه.');
    }
}
