<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Client;
use App\Models\CustomField;
use App\Models\IntakeForm;
use App\Models\ProofComment;
use App\Models\StorageConnection;
use App\Models\Task;
use App\Models\TaskList;
use App\Models\User;
use App\Services\Tasks\TaskLists;
use App\Services\Tasks\Tasks;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\NumberSequenceSeeder;
use Database\Seeders\TaskSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Intake forms and proofing (comments on images and videos, file versions). */
class TaskIntakeTest extends TestCase
{
    use RefreshDatabase;

    private User $u;

    private TaskList $list;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CurrencySeeder::class, NumberSequenceSeeder::class, TaskSeeder::class]);
        $this->u = $this->userWith(['tasks.view' => 'all', 'tasks.create' => 'all', 'tasks.edit' => 'all', 'tasks.assign' => 'all']);
        $this->actingAs($this->u);
        $this->list = TaskLists::create(Client::factory()->create(['name' => 'ULFED']), null, ['name' => 'طلبات التصميم'], null, false);
    }

    private function connectStorage(): void
    {
        Storage::fake('local');
        Http::fake(fn (Request $r) => match ($r->method()) {
            'PROPFIND' => Http::response('<multistatus/>', 207),
            'MKCOL', 'PUT' => Http::response('', 201),
            'HEAD' => Http::response('', 404),
            default => Http::response('', 200),
        });
        StorageConnection::create(['provider' => 'wd_mycloud', 'status' => 'connected', 'is_active' => true, 'root_folder_name' => 'Insight BOS',
            'max_upload_mb' => 20, 'credentials' => ['base_url' => 'https://nas.test/dav', 'username' => 'bos', 'password' => 'p']]);
    }

    private function form(array $d = []): IntakeForm
    {
        $this->post('/tasks/forms', $d + ['name' => 'طلب تصميم', 'task_list_id' => $this->list->id, 'fields' => [['key' => 'title']]])->assertSessionHasNoErrors();

        return IntakeForm::latest('id')->first();
    }

    public function test_internal_form_creates_a_task_with_its_fields(): void
    {
        $designer = $this->userWith(['tasks.view' => 'assigned']);
        $size = CustomField::create(['name' => 'المقاس', 'type' => 'dropdown', 'scope_type' => 'list', 'scope_id' => $this->list->id,
            'options' => ['choices' => [['id' => 'story', 'label' => 'ستوري'], ['id' => 'post', 'label' => 'منشور']]]]);
        CustomField::create(['name' => 'المعادلة', 'type' => 'formula', 'scope_type' => 'all', 'options' => ['formula' => '1']]);
        $form = $this->form(['fields' => [
            ['key' => 'title', 'label' => 'ما المطلوب؟'], ['key' => 'description'], ['key' => 'due'], ['key' => "cf_{$size->id}", 'required' => true],
            ['key' => 'cf_999'], ['key' => 'description'], // unknown and duplicate keys are dropped
        ], 'defaults' => ['assignees' => [$designer->id], 'priority' => 'high']]);
        $this->assertSame(['title', 'description', 'due', "cf_{$size->id}"], array_column($form->fields, 'key'));
        $this->assertSame('ما المطلوب؟', $form->fields[0]['label']);

        $this->get('/tasks/forms')->assertOk()->assertInertia(fn (Assert $p) => $p->component('tasks/Forms')->where('forms.0.can_manage', true));
        $this->getJson("/tasks/forms/fields?list={$this->list->id}")->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $size->id);

        // Internal forms need signing in; any employee may fill one.
        $this->actingAsGuest();
        $this->get("/forms/{$form->token}")->assertRedirect('/login');
        $sales = $this->userWith([]);
        $this->actingAs($sales)->get("/forms/{$form->token}")->assertOk()->assertInertia(fn (Assert $p) => $p->component('forms/Fill')->has('form.fields', 4));
        $this->post("/forms/{$form->token}", ['answers' => ['title' => '', "cf_{$size->id}" => 'x']])->assertSessionHasErrors(['answers.title', "answers.cf_{$size->id}"]);
        $this->post("/forms/{$form->token}", ['answers' => ['title' => 'بوستر المعرض', "cf_{$size->id}" => 'nope']])->assertSessionHasErrors("answers.cf_{$size->id}");
        $this->assertSame(0, Task::count());

        $this->post("/forms/{$form->token}", ['answers' => ['title' => 'بوستر المعرض', 'description' => 'مقاس A1', 'due' => '2026-12-10', "cf_{$size->id}" => 'post']])
            ->assertRedirect("/forms/{$form->token}")->assertSessionHas('form_sent');
        $t = Task::firstOrFail();
        $this->assertSame([$this->list->id, 'بوستر المعرض', 'high', '2026-12-10'], [$t->task_list_id, $t->title, $t->priority, $t->due_at->toDateString()]);
        $this->assertSame([$designer->id], $t->assignees()->pluck('users.id')->all());
        $this->assertSame('post', $t->fieldValues()->first()->value);
        $this->assertStringContainsString('مقاس A1', $t->description_text);
        $this->assertSame($sales->id, $t->created_by);
        $this->assertSame(1, $form->fresh()->submissions_count);

        // Only who manages the list manages its forms.
        $this->put("/tasks/forms/{$form->id}", ['is_active' => false])->assertForbidden();
        $this->actingAs($this->u)->put("/tasks/forms/{$form->id}", ['is_active' => false])->assertSessionHasNoErrors();
        $this->actingAs($sales)->post("/forms/{$form->token}", ['answers' => ['title' => 'أخرى', "cf_{$size->id}" => 'post']])->assertSessionHasErrors('form');
    }

    public function test_public_form_with_files_and_triage_notice(): void
    {
        $this->connectStorage();
        $form = $this->form(['is_public' => true, 'fields' => [['key' => 'title'], ['key' => 'name', 'required' => true], ['key' => 'email'], ['key' => 'files']], 'defaults' => ['due_in_days' => 3]]);
        $this->actingAsGuest();
        $this->travelTo('2026-12-01 10:00');

        $this->get("/forms/{$form->token}")->assertOk()->assertInertia(fn (Assert $p) => $p->component('forms/Fill')->where('signedIn', false));
        $this->post("/forms/{$form->token}", ['answers' => ['title' => 'فيديو', 'name' => 'سارة', 'email' => 'not-an-email']])->assertSessionHasErrors('answers.email');
        $this->post("/forms/{$form->token}", ['answers' => ['title' => 'سبام'], 'website' => 'http://spam.test'])->assertSessionHas('form_sent');
        $this->assertSame(0, Task::count());

        $this->post("/forms/{$form->token}", [
            'answers' => ['title' => 'فيديو تعريفي', 'name' => 'سارة', 'email' => 'sara@ulfed.org'],
            'files' => ['files' => [UploadedFile::fake()->image('logo.png')]],
        ])->assertSessionHasNoErrors()->assertSessionHas('form_sent', fn ($s) => $s['url'] === null && $s['code'] !== null);
        $t = Task::firstOrFail();
        $this->assertSame($this->u->id, $t->created_by); // the form's owner, for a public submission
        $this->assertSame('2026-12-04', $t->due_at->toDateString());
        $this->assertStringContainsString('سارة <sara@ulfed.org>', $t->description_text);
        $this->assertSame(0, $t->assignees()->count());
        $this->assertSame('logo.png', Attachment::where('attachable_type', 'task')->where('attachable_id', $t->id)->value('name'));
        // Nobody assigned by default: the form's owner is told there is a request to triage.
        $this->assertSame(1, $this->u->notifications()->where('data->kind', 'task.form')->count());
    }

    public function test_proof_comments_on_images_and_videos(): void
    {
        $designer = $this->userWith(['tasks.view' => 'assigned', 'tasks.edit' => 'assigned']);
        $t = Tasks::create($this->list, ['title' => 'تصميم البوستر', 'assignees' => [$designer->id]], $this->u);
        $img = $this->attachment($t, 'poster.png', 'image/png');
        $vid = $this->attachment($t, 'reel.mp4', 'video/mp4');
        $pdf = $this->attachment($t, 'brief.pdf', 'application/pdf');

        $this->get("/tasks/{$t->id}/proof/{$img->id}")->assertOk()->assertInertia(fn (Assert $p) => $p->component('tasks/Proof')
            ->where('file.kind', 'image')->where('file.src', "/attachments/{$img->id}/inline")->has('versions', 1));
        $this->get("/tasks/{$t->id}/proof/{$pdf->id}")->assertNotFound();
        $other = Tasks::create($this->list, ['title' => 'أخرى'], $this->u);
        $this->get("/tasks/{$other->id}/proof/{$img->id}")->assertNotFound();

        $this->post("/tasks/proof/{$img->id}/comments", ['body' => 'كبّر الشعار', 'x' => 150, 'y' => 20.5])->assertSessionHasNoErrors();
        $c = ProofComment::firstOrFail();
        $this->assertSame([100.0, 20.5, null], [$c->x, $c->y, $c->at_seconds]);
        $this->assertSame(1, $designer->notifications()->where('data->kind', 'task.proof')->count());
        $this->actingAs($designer)->post("/tasks/proof/{$img->id}/comments", ['body' => 'تم', 'parent_id' => $c->id, 'x' => 5])->assertSessionHasNoErrors();
        $this->assertNull(ProofComment::latest('id')->first()->x); // replies are not pinned
        $this->assertSame(1, $this->u->notifications()->where('data->kind', 'task.proof')->count());
        $this->patch("/tasks/proof-comments/{$c->id}", ['body' => 'تعديل'])->assertForbidden();
        $this->patch("/tasks/proof-comments/{$c->id}", ['resolved' => true])->assertSessionHasNoErrors();
        $this->assertNotNull($c->fresh()->resolved_at);

        $this->post("/tasks/proof/{$vid->id}/comments", ['body' => 'القطع هنا أسرع', 'at_seconds' => 12.4])->assertSessionHasNoErrors();
        $this->assertSame(12.4, ProofComment::where('attachment_id', $vid->id)->first()->at_seconds);
        $this->assertTrue(\App\Models\ActivityLog::where('subject_id', $t->id)->where('description', 'like', '%عند 0:12%')->exists());

        $this->actingAs($this->u)->get("/tasks/{$t->id}/proof/{$img->id}")->assertInertia(fn (Assert $p) => $p
            ->has('comments', 1)->where('comments.0.resolved', true)->has('comments.0.replies', 1)->where('comments.0.replies.0.author', $designer->name));
        $this->get("/tasks/{$t->id}")->assertInertia(fn (Assert $p) => $p->where('files.items', fn ($items) => collect($items)->firstWhere('id', $img->id)['proofable'] === true
            && collect($items)->firstWhere('id', $pdf->id)['proofable'] === false));
    }

    public function test_new_versions_of_a_proof_file(): void
    {
        $this->connectStorage();
        $t = Tasks::create($this->list, ['title' => 'البوستر'], $this->u);
        $v1 = $this->attachment($t, 'poster.png', 'image/png');
        ProofComment::create(['attachment_id' => $v1->id, 'task_id' => $t->id, 'user_id' => $this->u->id, 'body' => 'غيّر اللون']);

        $this->post("/tasks/proof/{$v1->id}/version", ['file' => UploadedFile::fake()->image('poster-v2.png')])->assertSessionHasNoErrors();
        $v2 = Attachment::latest('id')->first();
        $this->assertSame([2, $v1->id], [$v2->version, $v2->previous_id]);
        $this->assertSame('uploaded', $v2->status);
        $this->post("/tasks/proof/{$v1->id}/version", ['file' => UploadedFile::fake()->image('poster-v3.png')])->assertSessionHasErrors('file');

        $this->get("/tasks/{$t->id}/proof/{$v2->id}")->assertInertia(fn (Assert $p) => $p->has('versions', 2)
            ->where('versions.0.open', 1)->where('versions.1.open', 0)->has('comments', 0)->where('file.version', 2));
        $viewer = $this->userWith(['tasks.view' => 'all']);
        $this->actingAs($viewer)->post("/tasks/proof/{$v2->id}/version", ['file' => UploadedFile::fake()->image('x.png')])->assertForbidden();
    }

    private function attachment(Task $t, string $name, string $mime): Attachment
    {
        $a = Attachment::create(['attachable_type' => 'task', 'attachable_id' => $t->id, 'provider' => 'wd_mycloud', 'name' => $name, 'size' => 1000, 'mime' => $mime, 'uploaded_by' => $this->u->id]);
        $a->forceFill(['status' => 'uploaded', 'external_id' => '/x/'.$name])->save();

        return $a;
    }
}
