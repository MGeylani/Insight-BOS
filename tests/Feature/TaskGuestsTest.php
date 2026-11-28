<?php

namespace Tests\Feature;

use App\Models\AccessGrant;
use App\Models\Client;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskList;
use App\Models\User;
use App\Services\Tasks\TaskLists;
use App\Services\Tasks\Tasks;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\NumberSequenceSeeder;
use Database\Seeders\TaskSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Sharing clients / projects / lists with team members and guests (clients). */
class TaskGuestsTest extends TestCase
{
    use RefreshDatabase;

    private User $pm;

    private Project $project;

    private TaskList $exhibit;

    private TaskList $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CurrencySeeder::class, NumberSequenceSeeder::class, TaskSeeder::class]);
        $this->pm = $this->userWith(['tasks.view' => 'all', 'tasks.create' => 'all', 'tasks.edit' => 'all', 'tasks.assign' => 'all', 'projects.view' => 'all']);
        $this->actingAs($this->pm);
        $client = Client::factory()->create(['name' => 'ULFED']);
        $this->project = Project::factory()->create(['client_id' => $client->id, 'name' => 'STK Fuarı']);
        $this->exhibit = TaskLists::create($client, $this->project, ['name' => 'الجناح'], $this->pm->id, false);
        $this->other = TaskLists::create(Client::factory()->create(['name' => 'عميل آخر']), null, ['name' => 'محتوى'], $this->pm->id, false);
    }

    private function task(TaskList $list, string $title, array $d = []): Task
    {
        return Tasks::create($list, $d + ['title' => $title, 'assignees' => [$this->pm->id]], $this->pm);
    }

    private function doc(string $text): string
    {
        return json_encode(['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]]);
    }

    private function share(string $type, int $id, array $body): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->pm)->postJson("/tasks/access?type=$type&id=$id", $body);
    }

    public function test_sharing_a_list_with_a_team_member_beyond_their_scope(): void
    {
        $t = $this->task($this->other, 'منشورات ديسمبر');
        $designer = $this->userWith(['tasks.view' => 'assigned', 'tasks.create' => 'assigned', 'tasks.edit' => 'assigned']);
        $this->actingAs($designer)->get("/tasks/{$t->id}")->assertForbidden();

        $this->share('list', $this->other->id, ['user_id' => $designer->id, 'level' => 'view'])->assertOk()->assertJsonPath('grants.0.user.id', $designer->id);
        $this->actingAs($designer)->get("/tasks/{$t->id}")->assertOk();
        $this->get("/tasks?list={$this->other->id}")->assertInertia(fn (Assert $p) => $p->has('tasks', 1));
        $this->patch("/tasks/{$t->id}", ['title' => 'تعديل'])->assertForbidden();

        $grant = AccessGrant::firstOrFail();
        $this->actingAs($this->pm)->putJson("/tasks/access/{$grant->id}", ['level' => 'edit'])->assertOk();
        $this->actingAs($designer)->patch("/tasks/{$t->id}", ['title' => 'منشورات ديسمبر ٢'])->assertSessionHasNoErrors();
        $this->post('/tasks', ['task_list_id' => $this->other->id, 'title' => 'جديدة'])->assertRedirect();

        // Only who manages the place shares it.
        $this->getJson("/tasks/access?type=list&id={$this->other->id}")->assertForbidden();
        $this->actingAs($this->pm)->deleteJson("/tasks/access/{$grant->id}")->assertOk()->assertJsonCount(0, 'grants');
        $this->actingAs($designer)->get("/tasks/{$t->id}")->assertForbidden();
    }

    public function test_guest_sees_only_shared_non_internal_tasks_and_task_pages(): void
    {
        $visible = $this->task($this->exhibit, 'تصميم الجناح');
        $internal = $this->task($this->exhibit, 'تكلفة الطباعة الداخلية');
        $sub = $this->task($this->exhibit, 'عرض سعر المطبعة', ['parent_id' => $internal->id]);
        Tasks::update($internal, ['is_internal' => true]);
        $this->assertTrue($sub->fresh()->is_internal); // subtasks follow
        $elsewhere = $this->task($this->other, 'عميل آخر');
        $staffList = TaskLists::create(TaskLists::internalClient(), null, ['name' => 'داخلي الوكالة'], $this->pm->id, false);

        $this->share('project', $this->project->id, ['name' => 'سارة', 'email' => 'x'])->assertStatus(422);
        $r = $this->actingAs($this->pm)->postJson("/tasks/access/invite?type=project&id={$this->project->id}", ['name' => 'سارة (ULFED)', 'email' => 'Sara@ULFED.org', 'level' => 'view'])->assertOk();
        $this->assertStringContainsString('/reset-password/', $r->json('link'));
        $guest = User::where('email', 'sara@ulfed.org')->firstOrFail();
        $this->assertTrue($guest->is_guest);
        $this->postJson("/tasks/access/invite?type=project&id={$this->project->id}", ['name' => 'خطأ', 'email' => $this->pm->email, 'level' => 'view'])->assertJsonValidationErrors('email');

        $this->actingAs($guest);
        $this->get("/tasks?project={$this->project->id}")->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('tasks', fn ($rows) => collect($rows)->pluck('id')->all() === [$visible->id])
            ->where('tree', fn ($tree) => collect($tree)->pluck('name')->all() === ['ULFED'])
            ->where('can.share', false)->where('can.new_list', false));
        $this->get("/tasks/{$visible->id}")->assertOk()->assertInertia(fn (Assert $p) => $p->where('can.guest', true)->where('time', null)->has('activity', 0)->where('can.internal', false));
        foreach ([$internal, $sub, $elsewhere] as $t) {
            $this->get("/tasks/{$t->id}")->assertForbidden();
        }
        $this->get("/tasks?list={$staffList->id}")->assertForbidden(); // the agency's internal lists are not for guests
        $this->get('/dashboard')->assertRedirect('/my-work');
        $this->get('/my-work')->assertOk();
        $this->get('/crm/clients')->assertForbidden();
        $this->get('/tasks/workload')->assertForbidden();
        $this->get('/projects')->assertForbidden();

        // View level: comment yes, edit or create no.
        $this->patch("/tasks/{$visible->id}", ['title' => 'x'])->assertForbidden();
        $this->post('/tasks', ['task_list_id' => $this->exhibit->id, 'title' => 'طلب'])->assertForbidden();
        $this->post("/tasks/{$visible->id}/comments", ['body' => $this->doc('هل يمكن تكبير الشعار؟')])->assertSessionHasNoErrors();
        $this->assertFalse(Comment::latest('id')->first()->is_internal);

        // Edit level: create and edit there, but never mark internal.
        AccessGrant::where('user_id', $guest->id)->update(['level' => 'edit']);
        $this->post('/tasks', ['task_list_id' => $this->exhibit->id, 'title' => 'طلب من العميل'])->assertRedirect();
        $this->patch("/tasks/{$visible->id}", ['title' => 'تصميم الجناح (معتمد)'])->assertSessionHasNoErrors();
        $this->patch("/tasks/{$visible->id}", ['is_internal' => true])->assertForbidden();
    }

    public function test_internal_comments_stay_with_the_team(): void
    {
        $t = $this->task($this->exhibit, 'فيديو المعرض');
        $this->actingAs($this->pm)->postJson("/tasks/access/invite?type=list&id={$this->exhibit->id}", ['name' => 'سارة', 'email' => 'sara@ulfed.org', 'level' => 'view'])->assertOk();
        $guest = User::where('email', 'sara@ulfed.org')->firstOrFail();
        Tasks::syncPeople($t, 'watchers', [$this->pm->id, $guest->id]);

        $this->get("/tasks/{$t->id}")->assertInertia(fn (Assert $p) => $p->where('task.has_guests', true)->where('can.internal', true));
        $this->post("/tasks/{$t->id}/comments", ['body' => $this->doc('الميزانية لا تكفي')])->assertSessionHasNoErrors(); // default: internal
        $this->post("/tasks/{$t->id}/comments", ['body' => $this->doc('أرسلنا النسخة الأولى'), 'for_guests' => true])->assertSessionHasNoErrors();
        $internal = Comment::where('body_text', 'like', '%الميزانية%')->firstOrFail();
        $this->assertTrue($internal->is_internal);
        $this->post("/tasks/{$t->id}/comments", ['body' => $this->doc('سنعدّلها'), 'parent_id' => $internal->id, 'for_guests' => true]);
        $this->assertTrue(Comment::latest('id')->first()->is_internal); // replies follow their thread

        $this->assertSame(1, $guest->notifications()->where('data->kind', 'task.comment')->count());
        $this->actingAs($guest)->get("/tasks/{$t->id}")->assertInertia(fn (Assert $p) => $p->has('comments', 1)
            ->where('comments.0.body', fn ($b) => str_contains(json_encode($b, JSON_UNESCAPED_UNICODE), 'أرسلنا')));

        // Staff on a task no guest can see: comments are not internal by default.
        $plain = $this->task($this->other, 'بلا ضيوف');
        $this->actingAs($this->pm)->post("/tasks/{$plain->id}/comments", ['body' => $this->doc('عادي')]);
        $this->assertFalse(Comment::latest('id')->first()->is_internal);
    }
}
