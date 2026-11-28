<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\CustomField;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskList;
use App\Models\TaskType;
use App\Models\User;
use App\Services\Tasks\TaskLists;
use App\Services\Tasks\Tasks;
use App\Support\Formula;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\NumberSequenceSeeder;
use Database\Seeders\TaskSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TaskFieldsTest extends TestCase
{
    use RefreshDatabase;

    private User $u;

    private TaskList $list;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CurrencySeeder::class, NumberSequenceSeeder::class, TaskSeeder::class]);
        $this->u = $this->userWith([
            'tasks.view' => 'all', 'tasks.create' => 'all', 'tasks.edit' => 'all', 'tasks.delete' => 'all', 'tasks.assign' => 'all', 'settings.manage' => 'all',
        ]);
        $this->actingAs($this->u);
        $this->list = TaskLists::create(Client::factory()->create(['name' => 'ULFED']), null, ['name' => 'المطبوعات'], null, false);
    }

    private function field(array $data): CustomField
    {
        $this->post('/tasks/fields', $data + ['scope_type' => 'all'])->assertSessionHasNoErrors();

        return CustomField::latest('id')->first();
    }

    public function test_formula_calculator(): void
    {
        $this->assertSame(14.0, Formula::evaluate('2 + 3 * 4', []));
        $this->assertSame(20.0, Formula::evaluate('(2 + 3) * 4', []));
        $this->assertSame(-1.0, Formula::evaluate('-{1} + 1', ['1' => 2]));
        $this->assertSame(37.5, Formula::evaluate('{estimate} * 25', ['estimate' => 1.5]));
        $this->assertNull(Formula::evaluate('1 / 0', []));
        $this->assertNull(Formula::evaluate('2 +', []));
        $this->assertNull(Formula::evaluate('system("x")', []));
        $this->assertSame([3, 4], Formula::references('{3} * {4} + {3}'));
    }

    public function test_task_types(): void
    {
        $types = TaskType::orderBy('sort')->get();
        $this->assertSame(['مهمة', 'معلم', 'مخرَج للعميل', 'جلسة تصوير', 'منشور', 'اجتماع'], $types->pluck('name')->all());
        $this->seed(TaskSeeder::class);
        $this->assertSame(6, TaskType::count());

        $t = Tasks::create($this->list, ['title' => 'أ'], $this->u);
        $this->assertSame($types[0]->id, $t->task_type_id);
        $post = $types->firstWhere('name', 'منشور');
        $this->patch("/tasks/{$t->id}", ['task_type_id' => $post->id])->assertSessionHasNoErrors();
        $this->assertSame($post->id, $t->fresh()->task_type_id);
        $this->get('/tasks?types[]='.$post->id)->assertOk()->assertInertia(fn (Assert $page) => $page->has('tasks', 1)->where('tasks.0.type_id', $post->id)->has('types', 6));

        // Settings: a new type, and deleting a type moves its tasks to the default type.
        $this->post('/settings/task-types', ['name' => 'ريل', 'icon' => 'video', 'color' => '#ff0000', 'is_milestone' => false])->assertSessionHasNoErrors();
        $this->post('/settings/task-types', ['name' => 'x', 'icon' => 'not-an-icon', 'color' => '#ff0000'])->assertSessionHasErrors('icon');
        $this->delete("/settings/task-types/{$post->id}")->assertRedirect();
        $this->assertSame($types[0]->id, $t->fresh()->task_type_id);
        $this->delete("/settings/task-types/{$types[0]->id}")->assertSessionHas('error');
    }

    public function test_scopes_and_type_linked_fields(): void
    {
        $other = TaskLists::create($this->list->client, null, ['name' => 'ريلز'], null, false);
        $a = Tasks::create($this->list, ['title' => 'أ'], $this->u);
        $b = Tasks::create($other, ['title' => 'ب'], $this->u);
        $post = TaskType::where('name', 'منشور')->first();

        $this->field(['name' => 'رابط التصميم', 'type' => 'url']);
        $this->field(['name' => 'مقاس الطباعة', 'type' => 'text', 'scope_type' => 'list', 'scope_id' => $this->list->id]);
        $this->field(['name' => 'المنصة', 'type' => 'dropdown', 'type_ids' => [$post->id], 'options' => ['choices' => [['label' => 'انستقرام', 'color' => '#864690'], ['label' => 'تيك توك']]]]);

        $this->get("/tasks/{$a->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->has('customFields', 2));
        $this->get("/tasks/{$b->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->has('customFields', 1)->where('customFields.0.name', 'رابط التصميم'));
        $this->patch("/tasks/{$b->id}", ['task_type_id' => $post->id]);
        $this->get("/tasks/{$b->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->has('customFields', 2)->where('customFields.1.name', 'المنصة'));

        // A field that does not apply to the task is refused.
        $size = CustomField::where('name', 'مقاس الطباعة')->first();
        $this->put("/tasks/{$b->id}/fields/{$size->id}", ['value' => 'A4'])->assertSessionHasErrors('value');
        $this->put("/tasks/{$a->id}/fields/{$size->id}", ['value' => 'A4'])->assertSessionHasNoErrors();

        // The list location offers its fields for columns and filters.
        $this->get("/tasks?list={$this->list->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->has('customFields', 3)->where('canManageFields', true));
        $this->get("/tasks?list={$other->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->has('customFields', 2));
    }

    public function test_values_are_validated_filtered_sorted_and_exported(): void
    {
        $platform = $this->field(['name' => 'المنصة', 'type' => 'dropdown', 'options' => ['choices' => [['label' => 'انستقرام'], ['label' => 'تيك توك']]]]);
        [$ig, $tt] = array_column($platform->options['choices'], 'id');
        $price = $this->field(['name' => 'السعر', 'type' => 'money', 'options' => ['currency' => 'USD']]);
        $qty = $this->field(['name' => 'الكمية', 'type' => 'number']);
        $total = $this->field(['name' => 'الإجمالي', 'type' => 'formula', 'options' => ['formula' => '{'.$price->id.'} * {'.$qty->id.'}']]);
        $pct = $this->field(['name' => 'الإنجاز', 'type' => 'percent']);
        $url = $this->field(['name' => 'الرابط', 'type' => 'url']);
        $done = $this->field(['name' => 'تم التسليم', 'type' => 'checkbox']);
        $people = $this->field(['name' => 'المراجعون', 'type' => 'people']);

        $this->post('/tasks/fields', ['name' => 'خطأ', 'type' => 'formula', 'scope_type' => 'all', 'options' => ['formula' => '{'.$url->id.'} * 2']])->assertSessionHasErrors('options');
        $this->post('/tasks/fields', ['name' => 'فارغ', 'type' => 'dropdown', 'scope_type' => 'all', 'options' => ['choices' => []]])->assertSessionHasErrors('options');

        $a = Tasks::create($this->list, ['title' => 'أ'], $this->u);
        $b = Tasks::create($this->list, ['title' => 'ب'], $this->u);
        $set = fn ($t, $f, $v) => $this->put("/tasks/{$t->id}/fields/{$f->id}", ['value' => $v]);

        $set($a, $platform, $ig)->assertSessionHasNoErrors();
        $set($b, $platform, $tt)->assertSessionHasNoErrors();
        $set($a, $platform, 'nope')->assertSessionHasErrors('value');
        $set($a, $price, '12.5')->assertSessionHasNoErrors();
        $set($a, $qty, 4)->assertSessionHasNoErrors();
        $set($b, $price, 100)->assertSessionHasNoErrors();
        $set($a, $pct, 150)->assertSessionHasErrors('value');
        $set($a, $url, 'example.com')->assertSessionHasErrors('value');
        $set($a, $url, 'https://example.com/x')->assertSessionHasNoErrors();
        $set($a, $done, true)->assertSessionHasNoErrors();
        $set($a, $people, [User::factory()->create()->id])->assertSessionHasNoErrors();
        $set($a, $total, 5)->assertSessionHasErrors('value');

        // Rows carry values; the formula is computed.
        $this->get('/tasks?view=table&columns[]=cf:'.$total->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('view.columns', ['cf:'.$total->id])
            ->where('tasks.0.fields.'.$total->id, 50)->where('tasks.0.fields.'.$platform->id, $ig)->where('tasks.1.fields.'.$total->id, 0));

        // Filters by dropdown and checkbox, sorting by a field.
        $this->get("/tasks?fields[{$platform->id}]=$tt")->assertOk()->assertInertia(fn (Assert $page) => $page->has('tasks', 1)->where('tasks.0.id', $b->id));
        $this->get("/tasks?fields[{$done->id}]=1")->assertOk()->assertInertia(fn (Assert $page) => $page->has('tasks', 1)->where('tasks.0.id', $a->id));
        $this->get("/tasks?sort=cf:{$price->id}&dir=desc")->assertOk()->assertInertia(fn (Assert $page) => $page->where('tasks.0.id', $b->id));
        $this->get("/tasks?view=board&group=cf:{$platform->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->where('view.group', 'cf:'.$platform->id));

        // Clearing a value, the activity history and CSV export.
        $set($a, $url, null)->assertSessionHasNoErrors();
        $this->assertSame(0, $a->fieldValues()->where('custom_field_id', $url->id)->count());
        $this->assertTrue(\App\Models\ActivityLog::where('subject_type', 'task')->where('subject_id', $a->id)->where('description', 'like', '%«المنصة» ← انستقرام%')->exists());
        $csv = $this->get('/tasks/export?columns[]=cf:'.$platform->id.'&columns[]=cf:'.$total->id)->streamedContent();
        $this->assertStringContainsString('المنصة', $csv);
        $this->assertStringContainsString('انستقرام', $csv);
        $this->assertStringContainsString('50', $csv);
    }

    public function test_who_may_manage_fields(): void
    {
        $pm = $this->userWith(['tasks.view' => 'assigned', 'tasks.edit' => 'assigned']);
        $project = Project::factory()->create(['manager_id' => $pm->id]);
        $this->actingAs($pm)->post('/tasks/fields', ['name' => 'جولات التعديل', 'type' => 'number', 'scope_type' => 'project', 'scope_id' => $project->id])->assertSessionHasNoErrors();
        $this->actingAs($pm)->post('/tasks/fields', ['name' => 'عام', 'type' => 'text', 'scope_type' => 'all'])->assertForbidden();
        $f = CustomField::first();
        $this->actingAs($pm)->put("/tasks/fields/{$f->id}", ['name' => 'جولات المراجعة'])->assertSessionHasNoErrors();
        $this->assertSame('جولات المراجعة', $f->fresh()->name);
        $this->actingAs($pm)->put("/tasks/fields/{$f->id}", ['scope_type' => 'all'])->assertForbidden();

        $stranger = $this->userWith(['tasks.view' => 'all', 'tasks.edit' => 'assigned']);
        $this->actingAs($stranger)->delete("/tasks/fields/{$f->id}")->assertForbidden();
        $viewer = $this->userWith(['tasks.view' => 'all']);
        $t = Tasks::create(TaskLists::create($project->client, $project, ['name' => 'عام'], null, false), ['title' => 'x'], $this->u);
        $this->actingAs($viewer)->put("/tasks/{$t->id}/fields/{$f->id}", ['value' => 2])->assertForbidden();
        $this->actingAs($this->u)->delete("/tasks/fields/{$f->id}")->assertRedirect();
        $this->assertSoftDeleted($f);
    }
}
