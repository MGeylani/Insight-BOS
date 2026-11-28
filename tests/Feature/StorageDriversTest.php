<?php

namespace Tests\Feature;

use App\Models\StorageConnection;
use App\Services\Storage\Drivers\Dropbox;
use App\Services\Storage\Drivers\GoogleDrive;
use App\Services\Storage\Drivers\OneDrive;
use App\Services\Storage\Drivers\WebDav;
use App\Services\Storage\StorageException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Each storage driver against a simulated provider API (no real keys or network). */
class StorageDriversTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = tempnam(sys_get_temp_dir(), 'bos');
        file_put_contents($this->file, '0123456789'); // 10 bytes
        config([
            'services.google_drive' => ['client_id' => 'gid', 'client_secret' => 'gsecret'],
            'services.onedrive' => ['client_id' => 'mid', 'client_secret' => 'msecret', 'tenant' => 'common'],
            'services.dropbox' => ['client_id' => 'dkey', 'client_secret' => 'dsecret'],
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        OneDrive::$chunkBytes = 320 * 1024 * 32;
        Dropbox::$singleLimit = 140 * 1024 * 1024;
        Dropbox::$chunkBytes = 64 * 1024 * 1024;
        parent::tearDown();
    }

    private function connection(string $provider, array $credentials = []): StorageConnection
    {
        return StorageConnection::create([
            'provider' => $provider, 'status' => 'connected', 'is_active' => true, 'root_folder_name' => 'Insight BOS',
            'credentials' => $credentials ?: ['access_token' => 'tok', 'refresh_token' => 'ref', 'expires_at' => time() + 3600],
        ]);
    }

    public function test_google_drive_oauth_urls_exchange_and_refresh(): void
    {
        $c = $this->connection('google_drive', ['refresh_token' => 'old-ref', 'access_token' => 'expired', 'expires_at' => time() - 10]);
        $d = new GoogleDrive($c);

        $url = $d->authorizeUrl('st4te', 'https://bos.test/settings/storage/callback/google_drive');
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame('gid', $q['client_id']);
        $this->assertSame('st4te', $q['state']);
        $this->assertSame('offline', $q['access_type']);
        $this->assertSame('https://www.googleapis.com/auth/drive.file', $q['scope']);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'fresh', 'expires_in' => 3599]),
            'www.googleapis.com/drive/v3/about*' => Http::response(['user' => ['displayName' => 'Insight', 'emailAddress' => 'files@insight.test']]),
        ]);
        $this->assertSame('files@insight.test', $d->test());

        // The expired token was refreshed first, with the stored refresh token, and saved (encrypted).
        Http::assertSent(fn (Request $r) => $r->url() === 'https://oauth2.googleapis.com/token'
            && $r['grant_type'] === 'refresh_token' && $r['refresh_token'] === 'old-ref' && $r['client_secret'] === 'gsecret');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/drive/v3/about') && $r->hasHeader('Authorization', 'Bearer fresh'));
        $c->refresh();
        $this->assertSame('fresh', $c->credentials['access_token']);
        $this->assertSame('old-ref', $c->credentials['refresh_token']);
        $this->assertGreaterThan(time() + 3000, $c->credentials['expires_at']);
    }

    public function test_google_drive_exchange_requires_a_refresh_token(): void
    {
        $c = $this->connection('google_drive', ['x' => 1]);
        Http::fake(['oauth2.googleapis.com/token' => Http::sequence()
            ->push(['access_token' => 'a', 'expires_in' => 3600])
            ->push(['access_token' => 'a2', 'refresh_token' => 'r2', 'expires_in' => 3600])]);

        try {
            (new GoogleDrive($c))->exchange('code', 'https://bos.test/cb');
            $this->fail('expected exception');
        } catch (StorageException $e) {
            $this->assertStringContainsString('refresh token', $e->getMessage());
        }
        (new GoogleDrive($c))->exchange('code', 'https://bos.test/cb');
        Http::assertSent(fn (Request $r) => $r['grant_type'] === 'authorization_code' && $r['code'] === 'code' && $r['redirect_uri'] === 'https://bos.test/cb');
        $this->assertSame('r2', $c->fresh()->credentials['refresh_token']);
    }

    public function test_google_drive_folders_upload_and_delete(): void
    {
        $d = new GoogleDrive($this->connection('google_drive'));
        Http::fake(function (Request $r) {
            $url = $r->url();
            if ($r->method() === 'POST' && str_contains($url, 'uploadType=resumable')) {
                return Http::response('', 200, ['Location' => 'https://www.googleapis.com/upload/drive/v3/files?upload_id=SESSION']);
            }
            if ($r->method() === 'GET' && str_contains($url, '/drive/v3/files')) {
                // "العملاء" exists, anything else does not.
                return Http::response(['files' => str_contains(urldecode($url), "name = 'العملاء'") ? [['id' => 'F-clients']] : []]);
            }
            if ($r->method() === 'POST' && str_contains($url, '/drive/v3/files')) {
                return Http::response(['id' => 'F-new']);
            }
            if ($r->method() === 'PUT' && str_contains($url, 'upload_id=SESSION')) {
                return Http::response(['id' => 'FILE1', 'name' => 'brief.pdf', 'size' => '10', 'mimeType' => 'application/pdf', 'webViewLink' => 'https://drive.google.com/file/d/FILE1/view']);
            }
            if ($r->method() === 'PATCH') {
                return Http::response(['id' => 'FILE1', 'trashed' => true]);
            }

            return Http::response('unexpected', 500);
        });

        $this->assertSame('F-clients', $d->ensureChild('ROOT', 'العملاء'));
        $this->assertSame('F-new', $d->ensureChild('F-clients', "O'Neil / شركة"));
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), '/drive/v3/files')
            && $r['name'] === "O'Neil شركة" && $r['parents'] === ['F-clients'] && $r['mimeType'] === 'application/vnd.google-apps.folder');
        // Quotes inside names are escaped in the search query.
        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && str_contains(urldecode($r->url()), "name = 'O\\'Neil شركة'"));

        $out = $d->upload($this->file, 'brief.pdf', 'application/pdf', 'F-new');
        $this->assertSame(['external_id' => 'FILE1', 'url' => 'https://drive.google.com/file/d/FILE1/view', 'name' => 'brief.pdf', 'size' => 10, 'mime' => 'application/pdf'], $out);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'uploadType=resumable') && $r['parents'] === ['F-new']
            && $r->hasHeader('X-Upload-Content-Length', '10') && $r->hasHeader('X-Upload-Content-Type', 'application/pdf'));
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->body() === '0123456789');

        $d->delete('FILE1');
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_contains($r->url(), '/files/FILE1') && $r['trashed'] === true);
    }

    public function test_google_drive_errors_become_storage_exceptions(): void
    {
        $d = new GoogleDrive($this->connection('google_drive'));
        Http::fake(['*' => Http::response(['error' => ['message' => 'Insufficient permissions']], 403)]);

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('Insufficient permissions');
        $d->test();
    }

    public function test_onedrive_folders_and_chunked_upload(): void
    {
        OneDrive::$chunkBytes = 4;
        $d = new OneDrive($this->connection('onedrive'));
        $this->assertStringStartsWith('https://login.microsoftonline.com/common/oauth2/v2.0/authorize?', $d->authorizeUrl('s', 'https://bos.test/cb'));

        Http::fake(function (Request $r) {
            $url = urldecode($r->url());
            if ($r->method() === 'GET' && str_ends_with(explode('?', $url)[0], ':/العملاء')) {
                return Http::response(['error' => ['code' => 'itemNotFound', 'message' => 'not found']], 404);
            }
            if ($r->method() === 'GET' && str_contains($url, ':/موجود')) {
                return Http::response(['id' => 'EXISTING', 'folder' => ['childCount' => 0]]);
            }
            if ($r->method() === 'POST' && str_ends_with($url, '/children')) {
                return Http::response(['id' => 'NEWFOLDER', 'folder' => []], 201);
            }
            if (str_contains($url, 'createUploadSession')) {
                return Http::response(['uploadUrl' => 'https://upload.graph.test/session/1']);
            }
            if ($r->method() === 'PUT' && str_contains($url, 'upload.graph.test')) {
                return str_ends_with($r->header('Content-Range')[0], '9/10')
                    ? Http::response(['id' => 'ITEM1', 'name' => 'brief 1.pdf', 'size' => 10, 'webUrl' => 'https://onedrive.test/ITEM1', 'file' => ['mimeType' => 'application/pdf']], 201)
                    : Http::response(['nextExpectedRanges' => ['4-']], 202);
            }
            if ($r->method() === 'DELETE') {
                return Http::response('', 204);
            }

            return Http::response('unexpected', 500);
        });

        $this->assertSame('EXISTING', $d->ensureChild('root', 'موجود'));
        $this->assertSame('NEWFOLDER', $d->ensureChild('PARENT', 'العملاء'));
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), '/items/PARENT/children')
            && $r['name'] === 'العملاء' && $r['@microsoft.graph.conflictBehavior'] === 'fail');

        $out = $d->upload($this->file, 'brief.pdf', 'application/pdf', 'NEWFOLDER');
        $this->assertSame('ITEM1', $out['external_id']);
        $this->assertSame('https://onedrive.test/ITEM1', $out['url']);
        $ranges = [];
        foreach (Http::recorded() as [$req]) {
            if ($req->method() === 'PUT') {
                $ranges[] = $req->header('Content-Range')[0].'='.$req->body();
                $this->assertFalse($req->hasHeader('Authorization')); // the session URL is pre-authorised
            }
        }
        $this->assertSame(['bytes 0-3/10=0123', 'bytes 4-7/10=4567', 'bytes 8-9/10=89'], $ranges);
        Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), '/items/NEWFOLDER:/brief.pdf:/createUploadSession')
            && $r['item']['@microsoft.graph.conflictBehavior'] === 'rename');

        $d->delete('ITEM1');
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/me/drive/items/ITEM1'));
    }

    public function test_onedrive_token_request_carries_scope(): void
    {
        $c = $this->connection('onedrive', ['refresh_token' => 'r', 'access_token' => 'old', 'expires_at' => 0]);
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'new', 'refresh_token' => 'r-rotated', 'expires_in' => 3600]),
            'graph.microsoft.com/v1.0/me?*' => Http::response(['displayName' => 'Insight', 'mail' => 'it@insight.test']),
            'graph.microsoft.com/v1.0/me/drive*' => Http::response(['id' => 'drive']),
        ]);
        $this->assertSame('it@insight.test', (new OneDrive($c))->test());
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/common/oauth2/v2.0/token') && str_contains($r['scope'], 'Files.ReadWrite'));
        $this->assertSame('r-rotated', $c->fresh()->credentials['refresh_token']);
    }

    public function test_dropbox_folders_upload_session_and_delete(): void
    {
        $d = new Dropbox($this->connection('dropbox'));
        $this->assertStringContainsString('token_access_type=offline', $d->authorizeUrl('s', 'https://bos.test/cb'));

        Http::fake(function (Request $r) {
            $url = $r->url();
            if (str_ends_with($url, '/files/create_folder_v2')) {
                return $r['path'] === '/Insight BOS'
                    ? Http::response(['error_summary' => 'path/conflict/folder/..'], 409)
                    : Http::response(['metadata' => ['path_display' => $r['path'], 'id' => 'id:folder']]);
            }
            if (str_ends_with($url, '/files/upload') || str_ends_with($url, '/upload_session/finish')) {
                $arg = json_decode($r->header('Dropbox-API-Arg')[0], true);
                $path = $arg['path'] ?? $arg['commit']['path'];

                return Http::response(['id' => 'id:FILE', 'name' => basename($path), 'path_display' => $path, 'size' => 10]);
            }
            if (str_ends_with($url, '/upload_session/start')) {
                return Http::response(['session_id' => 'SESS']);
            }
            if (str_ends_with($url, '/upload_session/append_v2')) {
                return Http::response('null');
            }
            if (str_ends_with($url, '/files/delete_v2')) {
                return Http::response(['error_summary' => 'path_lookup/not_found/..'], 409);
            }
            if (str_ends_with($url, '/users/get_current_account')) {
                return Http::response(['email' => 'box@insight.test', 'name' => ['display_name' => 'Insight']]);
            }

            return Http::response('unexpected', 500);
        });

        $this->assertSame('box@insight.test', $d->test());
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/users/get_current_account') && $r->body() === 'null');

        $this->assertSame('/Insight BOS', $d->rootFolder()); // already exists: 409 conflict/folder is fine
        $folder = $d->ensureChild('/Insight BOS', 'العملاء');
        $this->assertSame('/Insight BOS/العملاء', $folder);

        $out = $d->upload($this->file, 'عقد موقّع.pdf', 'application/pdf', $folder);
        $this->assertSame('id:FILE', $out['external_id']);
        $this->assertSame('https://www.dropbox.com/home/Insight%20BOS/'.rawurlencode('العملاء').'?preview='.rawurlencode('عقد موقّع.pdf'), $out['url']);
        // The header is pure ASCII (non-ASCII escaped as \uXXXX), as Dropbox requires.
        Http::assertSent(function (Request $r) {
            if (! str_ends_with($r->url(), '/files/upload')) {
                return false;
            }
            $h = $r->header('Dropbox-API-Arg')[0];

            return mb_check_encoding($h, 'ASCII') && json_decode($h, true)['path'] === '/Insight BOS/العملاء/عقد موقّع.pdf'
                && json_decode($h, true)['autorename'] === true && $r->body() === '0123456789';
        });

        // Large files go through an upload session.
        Dropbox::$singleLimit = 5;
        Dropbox::$chunkBytes = 4;
        $d->upload($this->file, 'big.mov', 'video/quicktime', $folder);
        $bodies = [];
        foreach (Http::recorded() as [$req]) {
            if (str_contains($req->url(), 'upload_session')) {
                $bodies[] = basename($req->url()).':'.$req->body();
            }
        }
        $this->assertSame(['start:0123', 'append_v2:4567', 'finish:89'], $bodies);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/upload_session/finish')
            && json_decode($r->header('Dropbox-API-Arg')[0], true)['cursor'] === ['session_id' => 'SESS', 'offset' => 8]);

        $d->delete('id:FILE'); // already gone: not an error
    }

    public function test_webdav_folders_upload_with_free_name_and_errors(): void
    {
        $d = new WebDav($this->connection('wd_mycloud', ['base_url' => 'https://nas.insight.test/dav/', 'username' => 'bos', 'password' => 'secret']));
        $this->assertSame('https://nas.insight.test/dav/Insight%20BOS/'.rawurlencode('العملاء'), $d->url('/Insight BOS/العملاء'));

        Http::fake(function (Request $r) {
            return match ($r->method()) {
                'PROPFIND' => Http::response('<multistatus/>', 207),
                'MKCOL' => str_contains($r->url(), 'Insight%20BOS/') ? Http::response('', 201) : Http::response('', 405), // root exists
                'HEAD' => str_ends_with($r->url(), '/brief.pdf') ? Http::response('', 200) : Http::response('', 404), // name taken once
                'PUT' => Http::response('', 201),
                'DELETE' => Http::response('', 404),
                default => Http::response('unexpected', 500),
            };
        });

        $this->assertSame('bos@nas.insight.test', $d->test());
        Http::assertSent(fn (Request $r) => $r->method() === 'PROPFIND' && $r->hasHeader('Depth', '0') && $r->hasHeader('Authorization', 'Basic '.base64_encode('bos:secret')));
        $this->assertSame('/Insight BOS', $d->rootFolder());
        $folder = $d->ensureChild('/Insight BOS', 'العملاء');

        $out = $d->upload($this->file, 'brief.pdf', 'application/pdf', $folder);
        $this->assertSame('/Insight BOS/العملاء/brief (2).pdf', $out['external_id']);
        $this->assertNull($out['url']);
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && str_ends_with($r->url(), rawurlencode('brief (2).pdf')) && $r->body() === '0123456789');
        $d->delete($out['external_id']); // 404 is fine
    }

    public function test_webdav_wrong_password(): void
    {
        Http::fake(['*' => Http::response('', 401)]);
        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('401');
        (new WebDav($this->connection('wd_mycloud', ['base_url' => 'https://nas.insight.test', 'username' => 'x', 'password' => 'y'])))->test();
    }
}
