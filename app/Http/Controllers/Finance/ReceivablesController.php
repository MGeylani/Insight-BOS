<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\ActivityLogger;
use App\Services\Finance\Receivables;
use App\Support\Xlsx;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReceivablesController extends Controller
{
    /** Aging of what clients owe, per client. */
    public function index(Request $request): Response
    {
        return Inertia::render('finance/receivables/Index', [
            ...Receivables::aging($request->user()),
            'buckets' => config('finance.aging_buckets'),
        ]);
    }

    public function statement(Request $request, Client $client): Response
    {
        $this->requireClient($request, $client);
        [$from, $to] = $this->range($request);

        return Inertia::render('finance/receivables/Statement', [
            'client' => $client->only('id', 'name', 'currency'),
            'statement' => Receivables::statement($client, $from, $to),
            'range' => ['from' => $from, 'to' => $to],
            'can' => ['receive' => $request->user()->hasPermission('finance.create')],
        ]);
    }

    public function statementXlsx(Request $request, Client $client): BinaryFileResponse
    {
        $this->requireClient($request, $client);
        abort_unless($request->user()->hasPermission('finance.export'), 403);
        [$from, $to] = $this->range($request);
        $st = Receivables::statement($client, $from, $to);

        $x = new Xlsx;
        $s = $x->sheet('كشف الحساب');
        $s->widths([12, 16, 34, 14, 8, 14, 14, 14]);
        $s->row([['v' => "كشف حساب {$client->name}", 's' => ['bold' => true, 'size' => 14, 'color' => '00027C']]], [], 24);
        $s->row(["من $from إلى $to — المبالغ بالدولار بسعر صرف كل مستند"], ['color' => '64748B']);
        $s->row(['التاريخ', 'المستند', 'البيان', 'المبلغ الأصلي', 'العملة', 'مدين (USD)', 'دائن (USD)', 'الرصيد (USD)'],
            ['bold' => true, 'fill' => '00027C', 'color' => 'FFFFFF', 'border' => true, 'align' => 'center'], 20)->freeze(3);
        $s->row(['', '', 'الرصيد الافتتاحي', null, null, null, null, $st['opening']], ['bold' => true, 'border' => true, 'num' => '#,##0.00']);
        foreach ($st['rows'] as $r) {
            $s->row([
                \Carbon\CarbonImmutable::parse($r['date']), $r['number'], $r['label'], $r['amount'], $r['currency'],
                $r['debit_usd'] ?: null, $r['credit_usd'] ?: null, $r['balance_usd'],
            ], ['border' => true, 'num' => '#,##0.00']);
        }
        $s->row(['', '', 'الرصيد الختامي', null, null, $st['debit'], $st['credit'], $st['closing']], ['bold' => true, 'fill' => 'F1F5F9', 'border' => true, 'num' => '#,##0.00']);
        $path = tempnam(sys_get_temp_dir(), 'stmt');
        $x->save($path);
        ActivityLogger::log('finance.statement_exported', $client, "تصدير كشف حساب {$client->name}");

        return response()->download($path, "statement-{$client->id}-{$to}.xlsx", [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    /** A statement shows every invoice of the client: finance.view with scope all. */
    private function requireClient(Request $request, Client $client): void
    {
        abort_unless($request->user()->permissionScope('finance.view') === 'all', 403, 'كشف الحساب يحتاج صلاحية المالية بنطاق «الكل».');
    }

    /** @return array{0: string, 1: string} */
    private function range(Request $request): array
    {
        return [($request->date('from') ?? now()->startOfYear())->toDateString(), ($request->date('to') ?? today())->toDateString()];
    }
}
