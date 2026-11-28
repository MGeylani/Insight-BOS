<?php

namespace App\Services\Tasks;

use App\Models\TaskType;
use App\Support\Xlsx;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The Gantt view as an Excel workbook: a «المهام» sheet (one row per task) and a «Gantt» sheet
 * (a grid of days, weeks or months with each task's span filled in), from the same rows the view shows.
 */
class GanttExport
{
    public const SCALES = ['day', 'week', 'month'];

    /** Above this many columns the grid falls back to a coarser scale. */
    private const MAX_COLUMNS = 370;

    private const C = [
        'navy' => '00027C', 'brand' => '0082F6', 'done' => '10B981', 'overdue' => 'EF4444', 'milestone' => '481C4E',
        'critical' => 'F59E0B', 'head' => 'F1F5F9', 'today' => 'FDE68A', 'weekend' => 'F8FAFC',
    ];

    private const MONTHS = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];

    /**
     * @param  Collection<int, array<string, mixed>>  $rows  TaskPresenter::rows()
     * @return array{0: Xlsx, 1: string} the workbook and the scale actually used
     */
    public static function build(Collection $rows, string $scale, string $title): array
    {
        $scale = in_array($scale, self::SCALES, true) ? $scale : 'week';
        $milestoneTypes = TaskType::where('is_milestone', true)->pluck('id')->flip();
        $critical = self::criticalIds($rows);
        $codes = $rows->pluck('code', 'id');
        $x = new Xlsx;

        // ── Sheet 1: tasks ───────────────────────────────────────────────
        $s = $x->sheet('المهام');
        $s->widths([12, 44, 22, 22, 24, 13, 13, 10, 16, 18]);
        $head = ['bold' => true, 'fill' => self::C['navy'], 'color' => 'FFFFFF', 'border' => true, 'align' => 'center'];
        $s->row(['الرمز', 'العنوان', 'القائمة', 'المشروع', 'المسؤولون', 'البدء', 'التسليم', 'المدة (يوم)', 'الحالة', 'بانتظار'], $head, 22)->freeze(1, 2);
        foreach ($rows as $r) {
            [$start, $end] = self::span($r);
            $state = self::state($r, $milestoneTypes, $critical);
            $s->row([
                $r['code'],
                ['v' => str_repeat('    ', (int) $r['depth']).$r['title'], 's' => $r['depth'] ? [] : ['bold' => true]],
                $r['list']['name'] ?? '',
                $r['project']['name'] ?? '',
                collect($r['assignees'])->pluck('name')->implode('، '),
                self::date($r['start']),
                self::date($r['due']),
                $start ? $start->diffInDays($end) + 1 : null,
                ['v' => $r['status']['name'] ?? '', 's' => $state ? ['color' => self::C[$state], 'bold' => true] : []],
                collect($r['waiting_on'] ?? [])->map(fn ($id) => $codes[$id] ?? '#'.$id)->implode('، '),
            ], ['border' => true]);
        }

        // ── Sheet 2: the grid ────────────────────────────────────────────
        $dated = $rows->filter(fn ($r) => $r['start'] || $r['due'])->values();
        $g = $x->sheet('Gantt');
        if ($dated->isEmpty()) {
            $g->row(['لا مهام بتواريخ في هذا العرض.']);

            return [$x, $scale];
        }
        $min = $dated->map(fn ($r) => self::span($r)[0])->min();
        $max = $dated->map(fn ($r) => self::span($r)[1])->max();
        [$periods, $scale] = self::periods($min, $max, $scale);
        $n = count($periods);
        $today = CarbonImmutable::today();
        $workDays = array_map('intval', config('tasks.work_days', [1, 2, 3, 4, 5]));

        $g->widths([12, 36])->widths(array_fill(0, $n, $scale === 'day' ? 3.6 : ($scale === 'week' ? 6 : 9)), 3);
        // Row 1: title. Row 2: months/years over the periods. Row 3: period labels.
        $g->row([['v' => $title, 's' => ['bold' => true, 'size' => 14, 'color' => self::C['navy']]]], [], 24);
        $top = ['', ''];
        $runs = [];
        foreach ($periods as $i => [$from]) {
            $label = $scale === 'month' ? (string) $from->year : self::MONTHS[$from->month - 1].' '.$from->year;
            if (! $runs || end($runs)['label'] !== $label) {
                $runs[] = ['label' => $label, 'from' => $i, 'to' => $i];
            } else {
                $runs[array_key_last($runs)]['to'] = $i;
            }
            $top[] = '';
        }
        foreach ($runs as $run) {
            $top[$run['from'] + 2] = $run['label'];
        }
        $g->row($top, ['bold' => true, 'fill' => self::C['head'], 'align' => 'center', 'border' => true]);
        foreach ($runs as $run) {
            if ($run['to'] > $run['from']) {
                $g->merge(2, $run['from'] + 3, 2, $run['to'] + 3);
            }
        }
        $labels = ['الرمز', 'المهمة'];
        foreach ($periods as [$from, $to]) {
            $isToday = $today->betweenIncluded($from, $to);
            $labels[] = ['v' => match ($scale) {
                'day' => $from->day,
                'week' => $from->format('d/m'),
                default => self::MONTHS[$from->month - 1],
            }, 's' => $isToday ? ['fill' => self::C['today']] : []];
        }
        $g->row($labels, ['bold' => true, 'fill' => self::C['navy'], 'color' => 'FFFFFF', 'align' => 'center', 'border' => true], 20)->freeze(3, 2);

        foreach ($dated as $r) {
            [$start, $end] = self::span($r);
            $state = self::state($r, $milestoneTypes, $critical);
            $color = self::C[$state ?: 'brand'];
            $cells = [$r['code'], str_repeat('  ', (int) $r['depth']).$r['title']];
            foreach ($periods as [$from, $to]) {
                $on = $start->lte($to) && $end->gte($from);
                $weekend = $scale === 'day' && ! in_array($from->dayOfWeek, $workDays, true) ? ['fill' => self::C['weekend']] : [];
                $cells[] = ['v' => null, 's' => $on ? ['fill' => $color, 'border' => true] : $weekend + ['border' => true]];
            }
            $g->row($cells);
        }

        // Legend
        $g->row([]);
        foreach ([['brand', 'مهمة'], ['critical', 'على المسار الحرج'], ['milestone', 'معلم'], ['overdue', 'متأخرة'], ['done', 'مكتملة']] as [$k, $label]) {
            $g->row(['', $label, ['v' => null, 's' => ['fill' => self::C[$k], 'border' => true]]]);
        }

        return [$x, $scale];
    }

    /**
     * Same rule as the on-screen Gantt: from the open task that finishes last,
     * walk back through the blocker that finishes last.
     *
     * @return array<int, true>
     */
    public static function criticalIds(Collection $rows): array
    {
        $byId = $rows->filter(fn ($r) => $r['start'] || $r['due'])->keyBy('id');
        $end = fn ($r) => substr($r['due'] ?? $r['start'], 0, 10);
        $cur = $byId->filter(fn ($r) => ! $r['completed'])->sortByDesc($end)->first();
        $ids = [];
        for ($i = 0; $cur && $i < 200 && ! isset($ids[$cur['id']]); $i++) {
            $ids[$cur['id']] = true;
            $cur = collect($cur['waiting_on'] ?? [])->map(fn ($id) => $byId->get($id))->filter()->sortByDesc($end)->first();
        }

        return count($ids) > 1 ? $ids : [];
    }

    /** @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable} */
    private static function span(array $r): array
    {
        $due = $r['due'] ? CarbonImmutable::parse(substr($r['due'], 0, 10)) : null;
        $start = $r['start'] ? CarbonImmutable::parse(substr($r['start'], 0, 10)) : null;
        if (! $start && ! $due) {
            return [null, null];
        }
        $end = $due ?? $start;
        $start = $start && $start->lte($end) ? $start : $end;

        return [$start, $end];
    }

    private static function state(array $r, Collection $milestoneTypes, array $critical): ?string
    {
        return match (true) {
            $r['completed'] => 'done',
            $r['overdue'] => 'overdue',
            isset($critical[$r['id']]) => 'critical',
            $r['type_id'] && $milestoneTypes->has($r['type_id']) => 'milestone',
            default => null,
        };
    }

    /** @return array{0: array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>, 1: string} */
    private static function periods(CarbonImmutable $min, CarbonImmutable $max, string $scale): array
    {
        $order = array_flip(self::SCALES);
        for ($k = $order[$scale]; $k < count(self::SCALES); $k++) {
            $scale = self::SCALES[$k];
            $out = [];
            $cur = match ($scale) {
                'day' => $min,
                'week' => $min->subDays(($min->dayOfWeek + 1) % 7), // weeks start on Saturday
                default => $min->startOfMonth(),
            };
            while ($cur->lte($max) && count($out) <= self::MAX_COLUMNS) {
                $next = match ($scale) {
                    'day' => $cur->addDay(),
                    'week' => $cur->addWeek(),
                    default => $cur->addMonthNoOverflow()->startOfMonth(),
                };
                $out[] = [$cur, $next->subDay()];
                $cur = $next;
            }
            if (count($out) <= self::MAX_COLUMNS || $scale === 'month') {
                return [array_slice($out, 0, self::MAX_COLUMNS), $scale];
            }
        }

        return [[], $scale];
    }

    private static function date(?string $v): ?CarbonImmutable
    {
        return $v ? CarbonImmutable::parse(substr($v, 0, 10)) : null;
    }
}
