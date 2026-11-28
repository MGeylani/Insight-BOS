<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Client;
use App\Models\Comment;
use App\Models\Project;
use App\Models\StorageConnection;
use App\Models\Task;
use App\Models\TaskList;
use App\Models\User;
use App\Services\Tasks\TaskLists;
use App\Services\Tasks\Tasks;
use App\Support\RichText;
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

class TaskCollabTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CurrencySeeder::class, NumberSequenceSeeder::class, TaskSeeder::class]);
    }

    private function full(array $extra = []): User
    {
        return $this->userWith($extra + [
            'projects.view' => 'all', 'tasks.view' => 'all', 'tasks.create' => 'all', 'tasks.edit' => 'all', 'tasks.delete' => 'all', 'tasks.assign' => 'all',
        ]);
    }

    private function list(): TaskList
    {
        return TaskLists::create(Client::factory()->create(['name' => 'ULFED']), null, ['name' => 'المطبوعات'], null, false);
    }

    /** @return array<string, mixed> */
    private function doc(string $text, array $mentions = []): array
    {
        $content = [['type' => 'text', 'text' => $text]];
        foreach ($mentions as $u) {
            $content[] = ['type' => 'mention', 'attrs' => ['id' => (string) $u->id, 'label' => $u->name]];
        }

        return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => $content]]];
    }

    /** Notification kinds, oldest first. */
    private function kinds(User $u): array
    {
        return $u->notifications()->reorder()->orderBy('created_at')->orderBy('id')->get()->pluck('data.kind')->sort()->values()->all();
    }

    public function test_rich_text_is_cleaned(): void
    {
        $doc = RichText::normalize(json_encode(['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'رابط', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']], ['type' => 'bold']]],
                ['type' => 'script', 'content' => [['type' => 'text', 'text' => 'x']]],
            ]],
            ['type' => 'image', 'attrs' => ['src' => 'https://evil.test/x.png']],
            ['type' => 'image', 'attrs' => ['src' => '/attachments/5/inline', 'alt' => 'شعار']],
        ]]));
        $this->assertSame([['type' => 'bold']], $doc['content'][0]['content'][0]['marks']);
        $this->assertCount(1, $doc['content'][0]['content']);
        $this->assertCount(2, $doc['content']);
        $this->assertSame('/attachments/5/inline', $doc['content'][1]['attrs']['src']);
        $this->assertSame([5], RichText::images($doc));
        $this->assertNull(RichText::normalize(['type' => 'doc', 'content' => [['type' => 'paragraph']]]));
        $this->assertSame("سطر1\nسطر2", RichText::toPlain(RichText::fromPlain("سطر1\nسطر2")));
    }

    public function test_description_mentions_and_assignment_notify_once(): void
    {
        $u = $this->full();
        $a = User::factory()->create(['name' => 'سارة']);
        $a->syncPermissions(['tasks.view' => 'own']);
        $outsider = User::factory()->create(['name' => 'غريب']); // no task permissions: never notified
        $list = $this->list();
        $this->actingAs($u);
        $t = Tasks::create($list, ['title' => 'تصميم الرول أب'], $u);

        $this->actingAs($u)->patch("/tasks/{$t->id}", ['assignees' => [$a->id]])->assertSessionHasNoErrors();
        $this->assertSame(['task.assigned'], $this->kinds($a));
        $this->assertStringContainsString('ULF-1', $a->notifications()->first()->data['title']);

        $this->actingAs($u)->patch("/tasks/{$t->id}", ['description' => json_encode($this->doc('راجعي المقاسات ', [$a, $outsider]))])->assertSessionHasNoErrors();
        $t->refresh();
        $this->assertSame('mention', $t->description['content'][0]['content'][1]['type']);
        $this->assertStringContainsString('@سارة', $t->description_text);
        $this->assertSame(['task.assigned', 'task.mentioned'], $this->kinds($a)); // sorted
        $this->assertSame([], $this->kinds($outsider));

        // Saving again with the same mention does not notify again.
        $this->actingAs($u)->patch("/tasks/{$t->id}", ['description' => json_encode($this->doc('راجعي المقاسات جيداً ', [$a]))])->assertSessionHasNoErrors();
        $this->assertCount(2, $a->notifications()->get());

        // Watchers hear about status and due date changes; the actor never hears about their own change.
        $done = $list->statuses()->where('group', 'done')->first();
        $this->actingAs($u)->patch("/tasks/{$t->id}", ['status_id' => $done->id, 'due_date' => '2026-12-10'])->assertSessionHasNoErrors();
        $this->assertSame(['task.assigned', 'task.due', 'task.mentioned', 'task.status'], $this->kinds($a)); // sorted
        $this->assertSame([], $this->kinds($u));
    }

    public function test_threaded_assigned_comments(): void
    {
        $u = $this->full();
        $designer = User::factory()->create(['name' => 'مصمم']);
        $designer->syncPermissions(['tasks.view' => 'own']);
        $viewer = $this->userWith(['tasks.view' => 'all']);
        $this->actingAs($u);
        $t = Tasks::create($this->list(), ['title' => 'بوستر', 'assignees' => [$designer->id]], $u);
        $designer->notifications()->delete();

        // A viewer may comment; the comment is assigned to the designer.
        $this->actingAs($viewer)->post("/tasks/{$t->id}/comments", ['body' => json_encode($this->doc('غيّر لون الخلفية')), 'assigned_to' => $designer->id])
            ->assertSessionHasNoErrors();
        $c = Comment::first();
        $this->assertSame($designer->id, $c->assigned_to);
        $this->assertTrue($t->watchers()->where('users.id', $viewer->id)->exists());
        $this->assertSame(['task.comment_assigned'], $this->kinds($designer));
        $this->assertSame(['task.comment'], $this->kinds($u)); // creator watches the task

        // Replies attach to the thread's first comment, even when replying to a reply.
        $this->actingAs($designer)->post("/tasks/{$t->id}/comments", ['body' => json_encode($this->doc('تم')), 'parent_id' => $c->id])->assertSessionHasNoErrors();
        $r1 = Comment::latest('id')->first();
        $this->actingAs($u)->post("/tasks/{$t->id}/comments", ['body' => json_encode($this->doc('ممتاز')), 'parent_id' => $r1->id])->assertSessionHasNoErrors();
        $this->assertSame($c->id, Comment::latest('id')->first()->parent_id);

        // Only the author edits; the assignee resolves.
        $this->actingAs($designer)->patch("/tasks/comments/{$c->id}", ['body' => json_encode($this->doc('x'))])->assertForbidden();
        $this->actingAs($viewer)->patch("/tasks/comments/{$c->id}", ['body' => json_encode($this->doc('غيّر لون الخلفية للأزرق'))])->assertSessionHasNoErrors();
        $this->assertNotNull($c->fresh()->edited_at);
        $this->actingAs($designer)->post("/tasks/comments/{$c->id}/resolve", ['resolved' => true])->assertSessionHasNoErrors();
        $this->assertNotNull($c->fresh()->resolved_at);

        $this->actingAs($u)->get("/tasks/{$t->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('comments', 1)->has('comments.0.replies', 2)
                ->where('comments.0.assignee.id', $designer->id)->where('comments.0.can_edit', false)->where('comments.0.can_delete', true));
        $this->actingAs($u)->get('/tasks')->assertOk()->assertInertia(fn (Assert $page) => $page->where('tasks.0.comments_count', 3));

        // Someone else cannot delete another's comment without delete rights; the author can.
        $this->actingAs($designer)->delete("/tasks/comments/{$c->id}")->assertForbidden();
        $this->actingAs($viewer)->delete("/tasks/comments/{$c->id}")->assertRedirect();
        $this->assertSame(0, Comment::count());

        // Empty comments are refused; people who cannot see the task cannot comment.
        $this->actingAs($u)->post("/tasks/{$t->id}/comments", ['body' => json_encode(['type' => 'doc', 'content' => []])])->assertSessionHasErrors('body');
        $stranger = $this->userWith(['tasks.view' => 'own']);
        $this->actingAs($stranger)->post("/tasks/{$t->id}/comments", ['body' => 'مرحبا'])->assertForbidden();
    }

    public function test_task_attachments_upload_as_json_and_stream_inline(): void
    {
        Storage::fake('local');
        Http::fake(fn (Request $r) => match ($r->method()) {
            'PROPFIND' => Http::response('<multistatus/>', 207),
            'MKCOL' => Http::response('', 201),
            'HEAD' => Http::response('', 404),
            'PUT' => Http::response('', 201),
            'GET' => Http::response('PNG-BYTES', 200, ['Content-Type' => 'image/png']),
            default => Http::response('', 500),
        });
        StorageConnection::create([
            'provider' => 'wd_mycloud', 'status' => 'connected', 'is_active' => true, 'root_folder_name' => 'Insight BOS',
            'max_upload_mb' => 5, 'credentials' => ['base_url' => 'https://nas.test/dav', 'username' => 'bos', 'password' => 'p'],
        ]);
        $u = $this->full();
        $project = Project::factory()->create(['name' => 'جناح STK', 'number' => 'PRJ-2026-0001']);
        $list = TaskLists::create($project->client, $project, ['name' => 'المطبوعات'], null, false);
        $t = Tasks::create($list, ['title' => 'شعار'], $u);

        $res = $this->actingAs($u)->postJson('/attachments', ['type' => 'task', 'id' => $t->id, 'files' => [UploadedFile::fake()->image('paste.png')]])
            ->assertOk()->json('attachments.0');
        $a = Attachment::first();
        $this->assertSame("/attachments/{$a->id}/inline", $res['src']);
        $this->assertSame('uploaded', $a->status);
        $this->assertSame('task', $a->attachable_type);
        // Project tasks share the project's folder.
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && str_contains(urldecode($r->url()), 'PRJ-2026-0001 · جناح STK/paste.png'));

        $this->actingAs($u)->get("/attachments/{$a->id}/inline")->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->actingAs($this->userWith(['tasks.view' => 'own']))->get("/attachments/{$a->id}/inline")->assertForbidden();

        $this->actingAs($u)->get("/tasks/{$t->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('files.type', 'task')->has('files.items', 1)->where('files.can_upload', true));
    }
}
