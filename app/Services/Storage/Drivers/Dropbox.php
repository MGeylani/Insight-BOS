<?php

namespace App\Services\Storage\Drivers;

use App\Services\Storage\StorageException;
use GuzzleHttp\Psr7\Utils;

/**
 * Dropbox (API v2). Folders are addressed by path ("/Insight BOS/العملاء/…"), files by id ("id:…").
 * Files up to 140 MiB go in one request; larger ones use an upload session. Deleted files stay
 * recoverable from Dropbox's deleted files for the account's retention period.
 */
class Dropbox extends OAuthDriver
{
    private const RPC = 'https://api.dropboxapi.com/2';

    private const CONTENT = 'https://content.dropboxapi.com/2';

    public static int $singleLimit = 140 * 1024 * 1024;

    public static int $chunkBytes = 64 * 1024 * 1024;

    public static function configKey(): string
    {
        return 'dropbox';
    }

    protected function authorizeEndpoint(): string
    {
        return 'https://www.dropbox.com/oauth2/authorize';
    }

    protected function tokenEndpoint(): string
    {
        return 'https://api.dropboxapi.com/oauth2/token';
    }

    protected function authorizeParams(): array
    {
        return ['token_access_type' => 'offline', 'force_reapprove' => 'true'];
    }

    /** RPC call with a JSON body ("null" for endpoints without arguments). */
    private function rpc(string $endpoint, ?array $args, string $what, array $allow = [])
    {
        return $this->check($this->call(fn () => $this->api()
            ->withBody($args === null ? 'null' : json_encode($args), 'application/json')
            ->post(self::RPC.$endpoint)), $what, $allow);
    }

    /** Dropbox-API-Arg must be ASCII: json_encode escapes non-ASCII as \uXXXX by default. */
    private function arg(array $args): string
    {
        return json_encode($args, JSON_UNESCAPED_SLASHES);
    }

    public function test(): string
    {
        $r = $this->rpc('/users/get_current_account', null, 'فشل الاتصال بـ Dropbox');

        return $r->json('email') ?: ($r->json('name.display_name') ?: 'Dropbox');
    }

    public function rootFolder(): string
    {
        return $this->ensureChild('', $this->rootName());
    }

    public function ensureChild(string $parentId, string $name): string
    {
        $path = rtrim($parentId, '/').'/'.self::cleanName($name);
        $r = $this->rpc('/files/create_folder_v2', ['path' => $path, 'autorename' => false], 'فشل إنشاء المجلد', [409]);
        if ($r->status() === 409 && ! str_contains((string) $r->json('error_summary'), 'path/conflict/folder')) {
            throw new StorageException('فشل إنشاء المجلد (409) '.$r->json('error_summary'), 409);
        }

        return $r->json('metadata.path_display') ?: $path;
    }

    public function upload(string $localPath, string $name, string $mime, string $folderId): array
    {
        $size = filesize($localPath);
        $commit = ['path' => rtrim($folderId, '/').'/'.self::cleanName($name), 'mode' => 'add', 'autorename' => true, 'mute' => true];
        if ($size <= self::$singleLimit) {
            $body = Utils::streamFor(fopen($localPath, 'rb')); // closes its handle when released
            $r = $this->check($this->call(fn () => $this->api()
                ->withHeaders(['Dropbox-API-Arg' => $this->arg($commit)])
                ->withBody($body, 'application/octet-stream')
                ->post(self::CONTENT.'/files/upload')), 'فشل رفع الملف إلى Dropbox');
        } else {
            $handle = fopen($localPath, 'rb');
            try {
                $first = fread($handle, self::$chunkBytes);
                $start = $this->check($this->call(fn () => $this->api()
                    ->withHeaders(['Dropbox-API-Arg' => $this->arg(['close' => false])])
                    ->withBody($first, 'application/octet-stream')
                    ->post(self::CONTENT.'/files/upload_session/start')), 'فشل بدء الرفع إلى Dropbox');
                $sessionId = $start->json('session_id');
                $offset = strlen($first);
                while ($offset < $size - self::$chunkBytes) {
                    $chunk = fread($handle, self::$chunkBytes);
                    $this->check($this->call(fn () => $this->api()
                        ->withHeaders(['Dropbox-API-Arg' => $this->arg(['cursor' => ['session_id' => $sessionId, 'offset' => $offset], 'close' => false])])
                        ->withBody($chunk, 'application/octet-stream')
                        ->post(self::CONTENT.'/files/upload_session/append_v2')), 'فشل رفع جزء من الملف إلى Dropbox');
                    $offset += strlen($chunk);
                }
                $last = (string) stream_get_contents($handle);
                $r = $this->check($this->call(fn () => $this->api()
                    ->withHeaders(['Dropbox-API-Arg' => $this->arg(['cursor' => ['session_id' => $sessionId, 'offset' => $offset], 'commit' => $commit])])
                    ->withBody($last, 'application/octet-stream')
                    ->post(self::CONTENT.'/files/upload_session/finish')), 'فشل إكمال الرفع إلى Dropbox');
            } finally {
                fclose($handle);
            }
        }

        $display = (string) ($r->json('path_display') ?? $commit['path']);

        return [
            'external_id' => (string) $r->json('id'),
            'url' => self::webUrl($display),
            'name' => (string) ($r->json('name') ?? $name),
            'size' => (int) ($r->json('size') ?? $size),
            'mime' => $mime,
        ];
    }

    public function delete(string $externalId): void
    {
        $r = $this->rpc('/files/delete_v2', ['path' => $externalId], 'فشل حذف الملف من Dropbox', [409]);
        if ($r->status() === 409 && ! str_contains((string) $r->json('error_summary'), 'not_found')) {
            throw new StorageException('فشل حذف الملف من Dropbox (409) '.$r->json('error_summary'), 409);
        }
    }

    /** Opens the file's preview in the Dropbox web app (only for people with access to the account). */
    public static function webUrl(string $pathDisplay): string
    {
        $dir = trim(str_replace('\\', '/', dirname($pathDisplay)), '/');
        $enc = implode('/', array_map('rawurlencode', array_filter(explode('/', $dir), 'strlen')));

        return 'https://www.dropbox.com/home'.($enc ? '/'.$enc : '').'?preview='.rawurlencode(basename($pathDisplay));
    }

    /** File content (streamed), for showing pasted images inside BOS. */
    public function read(string $externalId): \Illuminate\Http\Client\Response
    {
        $r = $this->call(fn () => $this->api()->accept('*/*')->withOptions(['stream' => true])
            ->withHeaders(['Dropbox-API-Arg' => $this->arg(['path' => $externalId])])
            ->withBody('', 'text/plain')->post(self::CONTENT.'/files/download'));

        return $this->check($r, 'فشل قراءة الملف من Dropbox');
    }
}
