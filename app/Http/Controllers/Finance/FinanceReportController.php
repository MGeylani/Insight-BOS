<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Services\ActivityLogger;
use App\Services\Finance\FinanceReports;
use App\Support\Xlsx;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Finance home and company reports. Company-wide numbers need finance.view with scope all; Excel needs finance.export. */
class FinanceReportController extends Controller
{
    public function dashboard(Request $request): Response|RedirectResponse
    {
        if ($request->user()->permissionScope('finance.view') !== 'all') {
            return redirect('/finance/invoices');
        }

        return Inertia::render('finance/Dashboard', ['data' => FinanceReports::dashboard($request->user())]);
    }

    public function index(Request $request): Response
    {
        $this->requireAll($request);
        $p = $this->params($request);

        return Inertia::render('finance/reports/Index', [
            'params' => $p,
            'report' => $this->build($p),
            'groups' => FinanceReports::GROUPS,
            'accounts' => Account::withTrashed()->orderBy('name')->get(['id', 'name', 'currency']),
            'canExport' => $request->user()->hasPermission('finance.export'),
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        $this->requireAll($request);
        abort_unless($request->user()->hasPermission('finance.export'), 403);
        $p = $this->params($request);
        $r = $this->build($p);
        $x = new Xlsx;
        $title = match ($p['type']) {
            'pnl' => 'الأرباح والخسائر', 'cashflow' => 'التدفق النقدي', default => 'الربحية حسب '.FinanceReports::GROUPS[$p['group']],
        };
        $s = $x->sheet(mb_substr($title, 0, 31));
        $head = ['bold' => true, 'fill' => '00027C', 'color' => 'FFFFFF', 'border' => true, 'align' => 'center'];
        $num = ['border' => true, 'num' => '#,##0.00'];
        $s->row([['v' => "$title — Insight Media", 's' => ['bold' => true, 'size' => 14, 'color' => '00027C']]], [], 24);
        $s->row(["من {$p['from']} إلى {$p['to']} · بالدولار بسعر صرف كل مستند"], ['color' => '64748B']);

        if ($p['type'] === 'profit') {
            $s->widths([36, 14, 14, 12, 14, 14, 10]);
            $s->row(['الاسم', 'الإيراد', 'المصروفات', 'الساعات', 'تكلفة الساعات', 'الربح', 'الهامش %'], $head, 20)->freeze(3);
            foreach ($r['rows'] as $row) {
                $s->row([$row['label'], $row['revenue'], $row['expenses'], round($row['minutes'] / 60, 1), $row['labor'], $row['profit'], $row['margin']], $num);
            }
            $t = $r['totals'];
            $s->row(['المجموع', $t['revenue'], $t['expenses'], round($t['minutes'] / 60, 1), $t['labor'], $t['profit'], null], $num + ['bold' => true, 'fill' => 'F1F5F9']);
        } else {
            $months = $r['months'];
            $s->widths([30, ...array_fill(0, count($months) + 1, 12)]);
            $s->row(['البند', ...$months, 'المجموع'], $head, 20)->freeze(3, 1);
            $line = fn (string $label, array $values, float $total, array $style = []) => $s->row([$label, ...array_map(fn ($m) => $values[$m] ?? 0, $months), $total], $num + $style);
            $section = fn (string $label) => $s->row([$label], ['bold' => true, 'color' => '00027C', 'fill' => 'E8F3FE']);
            if ($p['type'] === 'pnl') {
                $section('الإيرادات');
                foreach ($r['revenue'] as $row) { $line($row['label'], $row['values'], $row['total']); }
                $line('مجموع الإيرادات', $r['totals']['revenue']['values'], $r['totals']['revenue']['total'], ['bold' => true]);
                $section('التكاليف المباشرة');
                foreach ($r['direct'] as $row) { $line($row['label'], $row['values'], $row['total']); }
                $line('مجمل الربح', $r['totals']['gross']['values'], $r['totals']['gross']['total'], ['bold' => true, 'fill' => 'F1F5F9']);
                $section('المصاريف العامة');
                foreach ($r['overhead'] as $row) { $line($row['label'], $row['values'], $row['total']); }
                $line('صافي الربح', $r['totals']['net']['values'], $r['totals']['net']['total'], ['bold' => true, 'fill' => 'DCFCE7']);
                $line('للعلم: تكلفة الساعات المسجلة', $r['totals']['labor']['values'], $r['totals']['labor']['total'], ['color' => '64748B']);
            } else {
                $s->row(['رصيد أول الفترة', ...array_fill(0, count($months), null), $r['opening']], $num + ['bold' => true]);
                $section('الداخل');
                foreach ($r['in'] as $row) { $line($row['label'], $row['values'], $row['total']); }
                $line('مجموع الداخل', $r['totals']['in']['values'], $r['totals']['in']['total'], ['bold' => true]);
                $section('الخارج');
                foreach ($r['out'] as $row) { $line($row['label'], $row['values'], $row['total']); }
                $line('مجموع الخارج', $r['totals']['out']['values'], $r['totals']['out']['total'], ['bold' => true]);
                $line('صافي التدفق', $r['totals']['net']['values'], $r['totals']['net']['total'], ['bold' => true, 'fill' => 'F1F5F9']);
                $s->row(['رصيد آخر الفترة', ...array_fill(0, count($months), null), $r['closing']], $num + ['bold' => true]);
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'rep');
        $x->save($path);
        ActivityLogger::log('finance.report_exported', null, "تصدير تقرير «{$title}» ({$p['from']} – {$p['to']})");

        return response()->download($path, "{$p['type']}-{$p['from']}-{$p['to']}.xlsx", [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    /** @return array{type: string, group: string, from: string, to: string, account: int|null} */
    private function params(Request $request): array
    {
        $type = in_array($request->input('type'), ['pnl', 'cashflow', 'profit'], true) ? $request->input('type') : 'pnl';
        $group = array_key_exists((string) $request->input('group'), FinanceReports::GROUPS) ? $request->input('group') : 'project';
        $from = ($request->date('from') ?? now()->startOfYear())->toDateString();
        $to = ($request->date('to') ?? today())->toDateString();
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }

        return ['type' => $type, 'group' => $group, 'from' => $from, 'to' => $to, 'account' => $request->integer('account') ?: null];
    }

    /** @param array<string, mixed> $p @return array<string, mixed> */
    private function build(array $p): array
    {
        return match ($p['type']) {
            'pnl' => FinanceReports::pnl($p['from'], $p['to']),
            'cashflow' => FinanceReports::cashflow($p['from'], $p['to'], $p['account']),
            default => FinanceReports::profitability($p['group'], $p['from'], $p['to']),
        };
    }

    private function requireAll(Request $request): void
    {
        abort_unless($request->user()->permissionScope('finance.view') === 'all', 403, 'تقارير المالية تحتاج صلاحية المالية بنطاق «الكل».');
    }
}
