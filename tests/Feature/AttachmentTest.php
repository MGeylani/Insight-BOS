<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Project;
use App\Models\StorageConnection;
use App\Models\StorageFolder;
use App\Models\User;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\NumberSequenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Attachments end to end, against a simulated WebDAV server (WD My Cloud). */
class AttachmentTest extends TestCase
{
    use RefreshDatabase;

    private bool $failPut = false;

    private int $folderMissing = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CurrencySeeder::class, NumberSequenceSeeder::class]);
        Storage::fake('local');
        Http::fake(function (Request $r) {
            if (! str_contains($r->url(), 'nas.test')) {
                return null; // other hosts are faked per test
            }

            return match ($r->method()) {
                'PROPFIND' => Http::response('<multistatus/>', 207),
                'MKCOL' => Http::response('', 201),
                'HEAD' => Http::response('', 404),
                'PUT' => $this->folderMissing-- > 0 ? Http::response('', 404)
                    : ($this->failPut ? Http::response('Service Unavailable', 503) : Http::response('', 201)),
                'GET' => Http::response('%PDF-file-bytes', 200, ['Content-Type' => 'application/pdf']),
                'DELETE' => Http::response('', 204),
                default => Http::response('', 500),
            };
        });
    }

    private function connect(array $extra = []): StorageConnection
    {
        return StorageConnection::create($extra + [
            'provider' => 'wd_mycloud', 'status' => 'connected', 'is_active' => true, 'root_folder_name' => 'Insight BOS',
            'max_upload_mb' => 5, 'credentials' => ['base_url' => 'https://nas.test/dav', 'username' => 'bos', 'password' => 'p'],
        ]);
    }

    private function pm(): User
    {
        return $this->userWith(['crm.view' => 'all', 'crm.edit' => 'all', 'projects.view' => 'all', 'projects.edit' => 'all']);
    }

    public function test_upload_to_a_project_goes_into_client_and_project_folders(): void
    {
        $c = $this->connect();
        $u = $this->pm();
        $client = Client::factory()->create(['name' => 'منبر ULFED']);
        $project = Project::factory()->create(['client_id' => $client->id, 'name' => 'جناح STK', 'number' => 'PRJ-2026-0001']);

        $this->actingAs($u)->post('/attachments', [
            'type' => 'project', 'id' => $project->id,
            'files' => [UploadedFile::fake()->create('brief.pdf', 20, 'application/pdf')],
        ])->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success');

        $a = Attachment::first();
        $this->assertSame('uploaded', $a->status);
        $this->assertSame('project', $a->attachable_type);
        $this->assertSame($c->id, $a->storage_connection_id);
        $this->assertSame("/Insight BOS/العملاء/منبر ULFED #{$client->id}/المشاريع/PRJ-2026-0001 · جناح STK/brief.pdf", $a->external_id);
        $this->assertNull($a->temp_path);
        $this->assertSame([], Storage::disk('local')->allFiles()); // nothing stays on the server
        $this->assertSame($u->id, $a->uploaded_by);
        $this->assertNotNull($c->fresh()->root_folder_id);

        // Folders are remembered: a second upload creates none.
        $mkcol = fn () => collect(Http::recorded())->filter(fn ($p) => $p[0]->method() === 'MKCOL')->count();
        $before = $mkcol();
        $this->actingAs($u)->post('/attachments', ['type' => 'project', 'id' => $project->id, 'files' => [UploadedFile::fake()->create('logo.png', 5, 'image/png')]]);
        $this->assertSame($before, $mkcol());
        $this->assertTrue(StorageFolder::where('key', "project:{$project->id}")->exists());

        // The project page lists them.
        $this->actingAs($u)->get("/projects/{$project->id}")->assertOk()
            ->assertInertia(fn (Assert $p) => $p->has('files.items', 2)->where('files.can_upload', true)->where('files.policy.connected', true)->where('files.type', 'project'));

        // A contract file of the same client lands in the client's "العقود" folder.
        $contract = Contract::factory()->create(['client_id' => $client->id]);
        $this->actingAs($u)->post('/attachments', ['type' => 'contract', 'id' => $contract->id, 'files' => [UploadedFile::fake()->create('signed.pdf', 5, 'application/pdf')]]);
        $this->assertSame("/Insight BOS/العملاء/منبر ULFED #{$client->id}/العقود/signed.pdf", Attachment::latest('id')->first()->external_id);
    }

    public function test_failed_upload_keeps_a_temp_copy_and_is_retried(): void
    {
        $this->connect();
        $u = $this->pm();
        $client = Client::factory()->create();
        $this->failPut = true;

        $this->actingAs($u)->post('/attachments', ['type' => 'client', 'id' => $client->id, 'files' => [UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')]])
            ->assertSessionHas('error');
        $a = Attachment::first();
        $this->assertSame('failed', $a->status);
        $this->assertSame(1, $a->attempts);
        $this->assertStringContainsString('503', $a->error);
        Storage::disk('local')->assertExists($a->temp_path);

        // Manual retry still failing.
        $this->actingAs($u)->post("/attachments/{$a->id}/retry")->assertSessionHas('error');
        $this->assertSame(2, $a->fresh()->attempts);

        // The scheduler retries once the server is back.
        $this->failPut = false;
        $this->travel(2)->minutes();
        $this->artisan('storage:retry')->assertSuccessful();
        $a->refresh();
        $this->assertSame('uploaded', $a->status);
        $this->assertNull($a->error);
        $this->assertSame([], Storage::disk('local')->allFiles());

        $this->actingAs($u)->get('/settings/storage')->assertForbidden(); // settings need settings.storage
    }

    public function test_auto_retry_stops_after_max_attempts(): void
    {
        $this->connect();
        $client = Client::factory()->create();
        $this->failPut = true;
        $this->actingAs($this->pm())->post('/attachments', ['type' => 'client', 'id' => $client->id, 'files' => [UploadedFile::fake()->create('a.pdf', 1)]]);
        $a = Attachment::first();
        $a->forceFill(['attempts' => Attachment::MAX_AUTO_ATTEMPTS])->save();
        $this->failPut = false;
        $this->travel(2)->minutes();
        $this->artisan('storage:retry');
        $this->assertSame('failed', $a->fresh()->status);
    }

    public function test_folder_removed_in_the_provider_is_recreated(): void
    {
        $c = $this->connect();
        $u = $this->pm();
        $client = Client::factory()->create();
        $this->actingAs($u)->post('/attachments', ['type' => 'client', 'id' => $client->id, 'files' => [UploadedFile::fake()->create('a.pdf', 1)]]);
        $this->assertTrue(StorageFolder::where('key', "client:{$client->id}")->exists());

        $this->folderMissing = 1; // someone deleted the folder in WD My Cloud
        $this->actingAs($u)->post('/attachments', ['type' => 'client', 'id' => $client->id, 'files' => [UploadedFile::fake()->create('b.pdf', 1)]]);
        $b = Attachment::latest('id')->first();
        $this->assertSame('failed', $b->status);
        $this->assertFalse(StorageFolder::where('storage_connection_id', $c->id)->where('key', "client:{$client->id}")->exists());

        $this->actingAs($u)->post("/attachments/{$b->id}/retry")->assertSessionHas('success');
        $this->assertSame('uploaded', $b->fresh()->status);
    }

    public function test_permissions_open_and_delete(): void
    {
        $this->connect();
        $u = $this->pm();
        $client = Client::factory()->create();
        $this->actingAs($u)->post('/attachments', ['type' => 'client', 'id' => $client->id, 'files' => [UploadedFile::fake()->create('a.pdf', 1, 'application/pdf')]]);
        $a = Attachment::first();

        // Viewers can open but not upload or delete.
        $viewer = $this->userWith(['crm.view' => 'all']);
        $this->actingAs($viewer)->post('/attachments', ['type' => 'client', 'id' => $client->id, 'files' => [UploadedFile::fake()->create('x.pdf', 1)]])->assertForbidden();
        $this->actingAs($viewer)->delete("/attachments/{$a->id}")->assertForbidden();
        $res = $this->actingAs($viewer)->get("/attachments/{$a->id}/open")->assertOk();
        $this->assertSame('%PDF-file-bytes', $res->streamedContent());
        $this->assertStringContainsString('application/pdf', $res->headers->get('Content-Type'));

        // Someone who cannot see the client cannot open its files.
        $stranger = $this->userWith(['crm.view' => 'own']);
        $this->actingAs($stranger)->get("/attachments/{$a->id}/open")->assertForbidden();

        $this->actingAs($viewer)->get("/crm/clients/{$client->id}")->assertOk()
            ->assertInertia(fn (Assert $p) => $p->has('files.items', 1)->where('files.can_upload', false)->where('files.items.0.can_delete', false));

        // Cloud providers open in their own web app.
        $a->forceFill(['url' => 'https://drive.google.com/file/d/X/view'])->save();
        $this->actingAs($viewer)->get("/attachments/{$a->id}/open")->assertRedirect('https://drive.google.com/file/d/X/view');

        $this->actingAs($u)->delete("/attachments/{$a->id}")->assertSessionHas('success');
        $this->assertSoftDeleted($a);
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/a.pdf'));
    }

    public function test_upload_policy_and_no_connection(): void
    {
        $u = $this->pm();
        $client = Client::factory()->create();
        $file = fn ($n = 'a.pdf', $kb = 1) => ['type' => 'client', 'id' => $client->id, 'files' => [UploadedFile::fake()->create($n, $kb)]];

        $this->actingAs($u)->post('/attachments', $file())->assertSessionHasErrors('file');
        $this->assertSame(0, Attachment::count());

        $this->connect(['allowed_types' => ['pdf', 'png']]);
        $this->actingAs($u)->post('/attachments', $file('virus.exe'))->assertSessionHasErrors('file');
        $this->actingAs($u)->post('/attachments', $file('big.pdf', 6 * 1024))->assertSessionHasErrors('file'); // over 5 MB
        $this->actingAs($u)->post('/attachments', ['type' => 'lead', 'id' => 1, 'files' => [UploadedFile::fake()->create('a.pdf')]])->assertSessionHasErrors('type');
        $this->assertSame(0, Attachment::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_storage_settings_webdav_connect_test_and_disconnect(): void
    {
        $admin = $this->userWith(['settings.storage' => 'all']);
        $this->actingAs($admin)->put('/settings/storage', ['provider' => 'wd_mycloud', 'max_upload_mb' => 50, 'root_folder_name' => 'BOS ملفات'])->assertSessionHasNoErrors();

        $this->actingAs($admin)->post('/settings/storage/webdav', ['base_url' => 'not a url', 'username' => 'u'])->assertSessionHasErrors(['base_url', 'password']);
        $this->actingAs($admin)->post('/settings/storage/webdav', ['base_url' => 'https://nas.test/dav/', 'username' => 'bos', 'password' => 's3cret'])->assertSessionHasNoErrors();
        $c = StorageConnection::active();
        $this->assertSame('connected', $c->status);
        $this->assertSame('bos@nas.test', $c->account_label);
        $this->assertSame('https://nas.test/dav', $c->credentials['base_url']);
        $this->assertSame('BOS ملفات', $c->root_folder_name);

        // The page never sends the password.
        $this->actingAs($admin)->get('/settings/storage')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('connection.status', 'connected')->where('connection.webdav.username', 'bos')
                ->missing('connection.webdav.password')->missing('connection.credentials')->where('providers.wd_mycloud.configured', true));

        // Changing the username keeps the stored password when the field is left empty.
        $this->actingAs($admin)->post('/settings/storage/webdav', ['base_url' => 'https://nas.test/dav', 'username' => 'bos2'])->assertSessionHasNoErrors();
        $this->assertSame('s3cret', $c->fresh()->credentials['password']);

        $this->actingAs($admin)->post('/settings/storage/test')->assertSessionHas('success');
        $this->actingAs($admin)->post('/settings/storage/disconnect')->assertSessionHas('success');
        $c->refresh();
        $this->assertSame('disconnected', $c->status);
        $this->assertNull($c->credentials);
    }

    public function test_oauth_connect_and_callback(): void
    {
        config(['services.dropbox' => ['client_id' => 'dkey', 'client_secret' => 'dsecret']]);
        $admin = $this->userWith(['settings.storage' => 'all']);
        $this->actingAs($admin)->put('/settings/storage', ['provider' => 'google_drive', 'max_upload_mb' => 50]);

        // Google keys are not configured yet.
        $this->actingAs($admin)->get('/settings/storage/connect/google_drive')->assertSessionHas('error');
        $this->actingAs($admin)->get('/settings/storage')->assertInertia(fn (Assert $p) => $p->where('providers.google_drive.configured', false)
            ->where('providers.dropbox.configured', true)->where('providers.google_drive.redirect_uri', url('/settings/storage/callback/google_drive')));
        // Only the active provider can be connected.
        $this->actingAs($admin)->get('/settings/storage/connect/dropbox')->assertStatus(422);

        $this->actingAs($admin)->put('/settings/storage', ['provider' => 'dropbox', 'max_upload_mb' => 50]);
        $res = $this->actingAs($admin)->get('/settings/storage/connect/dropbox')->assertRedirect();
        $this->assertStringStartsWith('https://www.dropbox.com/oauth2/authorize?', $res->headers->get('Location'));
        $state = session('storage_oauth')['state'];
        $this->assertStringContainsString("state=$state", $res->headers->get('Location'));

        Http::fake([
            'api.dropboxapi.com/oauth2/token' => Http::response(['access_token' => 'AT', 'refresh_token' => 'RT', 'expires_in' => 14400]),
            'api.dropboxapi.com/2/users/get_current_account' => Http::response(['email' => 'box@insight.test']),
        ]);

        // A wrong state is refused.
        $this->actingAs($admin)->withSession(['storage_oauth' => ['state' => $state, 'provider' => 'dropbox']])
            ->get('/settings/storage/callback/dropbox?code=C&state=wrong')->assertRedirect('/settings/storage')->assertSessionHas('error');

        $this->actingAs($admin)->withSession(['storage_oauth' => ['state' => $state, 'provider' => 'dropbox']])
            ->get("/settings/storage/callback/dropbox?code=C&state=$state")->assertRedirect('/settings/storage')->assertSessionHas('success');
        $c = StorageConnection::active();
        $this->assertSame('connected', $c->status);
        $this->assertSame('box@insight.test', $c->account_label);
        $this->assertSame('RT', $c->credentials['refresh_token']);
        $this->assertSame($admin->id, $c->connected_by);
        $this->assertNotSame('RT', StorageConnection::query()->toBase()->where('id', $c->id)->value('credentials')); // encrypted at rest
    }
}
