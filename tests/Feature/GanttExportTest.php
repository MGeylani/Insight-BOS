<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Task;
use App\Models\TaskList;
use App\Models\User;
use App\Services\Tasks\Dependencies;
use App\Services\Tasks\GanttExport;
use App\Services\Tasks\TaskLists;
use App\Services\Tasks\TaskPresenter;
use App\Services\Tasks\TaskQuery;
use App\Services\Tasks\Tasks;
use App\Support\Xlsx;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\NumberSequenceSeeder;
use Database\Seeders\TaskSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

class GanttExportTest extends TestCase
{
    use RefreshDatabase;

    private User $u;

    private TaskList $list;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CurrencySeeder::class, NumberSequenceSeeder::class, TaskSeeder::class]);
        $this->u = $this->userWith(['tasks.view' => 'all', 'tasks.create' => 'all', 'tasks.edit' => 'all']);
        $this->actingAs($this->u);
        $this->list = TaskLists::create(Client::factory()->create(['name' => 'ULFED']), null, ['name' => 'المطبوعات'], null, false);
    }

    private function task(array $data): Task
    {
        return Tasks::create($this->list, $data, $this->u);
    }

    /** @return array<string, string> entry name → contents */
    private function unzip(string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($path, $bytes);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $out = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $out[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
        }
        $zip->close();
        unlink($path);

        return $out;
    }

    public function test_xlsx_writer_produces_valid_parts(): void
    {
        $x = new Xlsx;
        $s = $x->sheet('المهام/أ');
        $s->widths([10, 20])->row(['أ', 'ب & <ج>'], ['bold' => true, 'fill' => 'F1F5F9'])->freeze(1, 1)->merge(1, 1, 1, 2);
        $s->row([1.5, now()->toImmutable(), true, null, ['v' => 'x', 's' => ['fill' => '0082F6']]]);
        $path = tempnam(sys_get_temp_dir(), 'x');
        $x->save($path);
        $files = $this->unzip(file_get_contents($path));
        unlink($path);

        $this->assertArrayHasKey('xl/workbook.xml', $files);
        $this->assertStringContainsString('name="المهام أ"', $files['xl/workbook.xml']);
        $sheet = $files['xl/worksheets/sheet1.xml'];
        $this->assertNotFalse(simplexml_load_string($sheet));
        $this->assertNotFalse(simplexml_load_string($files['xl/styles.xml']));
        $this->assertStringContainsString('ب &amp; &lt;ج&gt;', $sheet);
        $this->assertStringContainsString('rightToLeft="1"', $sheet);
        $this->assertStringContainsString('state="frozen"', $sheet);
        $this->assertStringContainsString('<mergeCell ref="A1:B1"/>', $sheet);
        $this->assertStringContainsString('FF0082F6', $files['xl/styles.xml']);
        $this->assertSame('AA', Xlsx::col(27));
    }

    public function test_gantt_export_downloads_workbook_of_the_view(): void
    {
        $d = fn (int $n) => now()->addDays($n)->toDateString(); // future dates: nothing overdue
        $a = $this->task(['title' => 'التصميم', 'start_date' => $d(3), 'due_date' => $d(7)]);
        $b = $this->task(['title' => 'الطباعة', 'start_date' => $d(8), 'due_date' => $d(12)]);
        $this->task(['title' => 'بلا تاريخ']);
        Dependencies::add($b, $a, 'waiting', $this->u->id);

        $res = $this->get('/tasks/export/gantt?scale=day&list='.$this->list->id);
        $res->assertOk();
        $this->assertStringContainsString('spreadsheetml', $res->headers->get('Content-Type'));
        $files = $this->unzip(file_get_contents($res->baseResponse->getFile()->getPathname()));

        $tasks = $files['xl/worksheets/sheet1.xml'];
        $this->assertStringContainsString('التصميم', $tasks);
        $this->assertStringContainsString('بلا تاريخ', $tasks); // the tasks sheet lists every task
        $this->assertStringContainsString($a->fresh()->code(), $tasks); // «بانتظار» names the blocker

        $grid = $files['xl/worksheets/sheet2.xml'];
        $this->assertStringNotContainsString('بلا تاريخ', $grid); // only dated tasks on the grid
        // Day scale: 10 days =  10 period columns after code + title (C … L).
        $this->assertStringContainsString('r="L3"', $grid);
        $this->assertStringNotContainsString('r="M3"', $grid);
        $this->assertStringContainsString('FFF59E0B', $files['xl/styles.xml']); // critical path colour used
    }

    public function test_critical_path_and_scale_fallback(): void
    {
        $d = fn (int $n) => now()->addDays($n)->toDateString();
        $a = $this->task(['title' => 'أ', 'due_date' => $d(5)]);
        $b = $this->task(['title' => 'ب', 'due_date' => $d(20)]);
        $this->task(['title' => 'ج', 'due_date' => $d(1)]);
        Dependencies::add($b, $a, 'waiting', $this->u->id);
        $rows = TaskPresenter::rows(TaskQuery::build($this->u, ['list' => $this->list->id], []));
        $this->assertEqualsCanonicalizing([$a->id, $b->id], array_keys(GanttExport::criticalIds($rows)));

        // Two years of days is too wide: falls back to weeks.
        $this->task(['title' => 'بعيدة', 'due_date' => now()->addYears(2)->toDateString()]);
        $rows = TaskPresenter::rows(TaskQuery::build($this->u, ['list' => $this->list->id], []));
        [, $scale] = GanttExport::build($rows, 'day', 'x');
        $this->assertSame('week', $scale);
    }

    public function test_guests_cannot_export(): void
    {
        $guest = User::factory()->create(['is_guest' => true]);
        $this->actingAs($guest)->get('/tasks/export/gantt')->assertForbidden();
    }
}
