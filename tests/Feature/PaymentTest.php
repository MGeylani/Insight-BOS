<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\ExchangeRate;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\Finance\Accounts;
use App\Services\Finance\Receivables;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\NumberSequenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CurrencySeeder::class, NumberSequenceSeeder::class]);
        ExchangeRate::create(['currency_code' => 'TRY', 'date' => today(), 'rate' => 40]);
    }

    private function finance(string $scope = 'all'): User
    {
        return $this->userWith([
            'finance.view' => $scope, 'finance.create' => $scope, 'finance.edit' => $scope, 'finance.delete' => $scope, 'finance.export' => $scope,
            'crm.view' => 'all', 'crm.create' => 'all', 'crm.edit' => 'all',
        ]);
    }

    private function account(string $currency = 'USD', float $opening = 0): Account
    {
        return Account::create(['name' => "صندوق $currency", 'type' => 'cash', 'currency' => $currency, 'opening_balance' => $opening, 'opening_date' => today()->subMonth()]);
    }

    /** An issued USD invoice of $amount for the client (acting user owns it). */
    private function invoice(Client $client, float $amount = 1000, array $extra = []): Invoice
    {
        $this->post('/finance/invoices', $extra + [
            'client_id' => $client->id, 'title' => 'خدمات', 'currency' => 'USD',
            'items' => [['name' => 'بند', 'quantity' => 1, 'unit_price' => $amount]],
        ])->assertSessionHasNoErrors();
        $inv = Invoice::latest('id')->first();
        $this->post("/finance/invoices/{$inv->id}/issue")->assertSessionHasNoErrors();

        return $inv->fresh();
    }

    private function receive(Client $client, Account $acc, float $amount, array $allocations = [], array $extra = [])
    {
        return $this->post('/finance/payments', $extra + [
            'client_id' => $client->id, 'account_id' => $acc->id, 'date' => today()->toDateString(),
            'amount' => $amount, 'method' => 'cash', 'allocations' => $allocations,
        ]);
    }

    public function test_receipts_settle_invoices_partially_then_fully(): void
    {
        $u = $this->finance();
        $client = Client::factory()->create();
        $acc = $this->account();
        $this->actingAs($u);
        $inv = $this->invoice($client, 1000);

        $this->receive($client, $acc, 400, [['invoice_id' => $inv->id, 'amount' => 400]])->assertSessionHasNoErrors();
        $p = Payment::first();
        $this->assertSame('RCPT-'.now()->year.'-0001', $p->number);
        $this->assertSame(400.0, $inv->fresh()->paidAmount());
        $this->assertSame('partial', $inv->fresh()->displayStatus());

        // More than what is left on the invoice is refused.
        $this->receive($client, $acc, 700, [['invoice_id' => $inv->id, 'amount' => 700]])->assertSessionHasErrors('allocations');
        $this->assertSame(1, Payment::count()); // the whole receipt rolled back

        $this->receive($client, $acc, 600, [['invoice_id' => $inv->id, 'amount' => 600]])->assertSessionHasNoErrors();
        $this->assertSame(0.0, $inv->fresh()->balance());
        $this->assertSame('paid', $inv->fresh()->displayStatus());

        // A paid invoice cannot be cancelled.
        $this->post("/finance/invoices/{$inv->id}/cancel", ['reason' => 'x'])->assertSessionHasErrors();

        // Lists and filters.
        $this->get('/finance/invoices?status=paid')->assertInertia(fn (Assert $a) => $a->has('invoices.data', 1));
        $this->get('/finance/invoices?status=open')->assertInertia(fn (Assert $a) => $a->has('invoices.data', 0));
        $this->get("/finance/invoices/{$inv->id}")->assertInertia(fn (Assert $a) => $a->has('payments', 2));
        $this->get('/finance/payments')->assertOk()->assertInertia(fn (Assert $a) => $a->component('finance/payments/Index')->has('payments.data', 2));
        $this->get("/finance/payments/{$p->id}")->assertOk()->assertInertia(fn (Assert $a) => $a->component('finance/payments/Show'));
        $this->get("/finance/payments/create?invoice_id={$inv->id}")->assertOk()->assertInertia(fn (Assert $a) => $a->where('preset.client_id', $client->id));
    }

    public function test_credit_allocate_deallocate_refund_and_reverse(): void
    {
        $u = $this->finance();
        $client = Client::factory()->create();
        $acc = $this->account('USD', 100);
        $this->actingAs($u);
        $inv = $this->invoice($client, 300);

        // Paid in advance: no allocation → client credit.
        $this->receive($client, $acc, 500)->assertSessionHasNoErrors();
        $p = Payment::first();
        $this->assertSame(500.0, $p->unallocated());
        $this->getJson("/finance/client-open/{$client->id}")->assertJsonCount(1, 'invoices')->assertJsonCount(1, 'credits');
        $this->get("/finance/invoices/{$inv->id}")->assertInertia(fn (Assert $a) => $a->has('credits', 1));

        $this->post("/finance/payments/{$p->id}/allocate", ['invoice_id' => $inv->id, 'amount' => 300])->assertSessionHasNoErrors();
        $this->assertSame(200.0, $p->fresh()->unallocated());
        $this->assertSame('paid', $inv->fresh()->displayStatus());

        // Refund only from what is not allocated.
        $this->post("/finance/payments/{$p->id}/refund", ['amount' => 250, 'account_id' => $acc->id, 'date' => today()->toDateString(), 'method' => 'cash'])->assertSessionHasErrors('amount');
        $this->post("/finance/payments/{$p->id}/refund", ['amount' => 150, 'account_id' => $acc->id, 'date' => today()->toDateString(), 'method' => 'cash'])->assertSessionHasNoErrors();
        $this->assertSame(50.0, $p->fresh()->unallocated());
        $this->assertSame(100 + 500 - 150.0, Accounts::balance($acc));

        // Deallocate: invoice reopens, money becomes credit again.
        $alloc = $p->allocations()->first();
        $this->delete("/finance/allocations/{$alloc->id}")->assertSessionHasNoErrors();
        $this->assertSame(300.0, $inv->fresh()->balance());
        $this->assertSame(350.0, $p->fresh()->unallocated());

        // Reverse: refunds first.
        $this->post("/finance/payments/{$p->id}/reverse", ['reason' => 'خطأ'])->assertSessionHasErrors('reason');
        $refund = Payment::where('kind', 'refund')->first();
        $this->post("/finance/payments/{$refund->id}/reverse", ['reason' => 'خطأ'])->assertSessionHasNoErrors();
        $this->post("/finance/payments/{$p->id}/allocate", ['invoice_id' => $inv->id, 'amount' => 100])->assertSessionHasNoErrors();
        $this->post("/finance/payments/{$p->id}/reverse", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post("/finance/payments/{$p->id}/reverse", ['reason' => 'سُجّل مرتين'])->assertSessionHasNoErrors();
        $p->refresh();
        $this->assertSame('reversed', $p->status);
        $this->assertSame(0, $p->allocations()->count());
        $this->assertSame(300.0, $inv->fresh()->balance());
        $this->assertSame(100.0, Accounts::balance($acc));
        // A reversed receipt allocates nothing.
        $this->post("/finance/payments/{$p->id}/allocate", ['invoice_id' => $inv->id, 'amount' => 10])->assertSessionHasErrors('payment');
    }

    public function test_cross_currency_receipt_and_future_date(): void
    {
        $u = $this->finance();
        $client = Client::factory()->create();
        $try = $this->account('TRY');
        $this->actingAs($u);
        $inv = $this->invoice($client, 100);

        $this->receive($client, $try, 4000, [['invoice_id' => $inv->id, 'amount' => 100]])->assertSessionHasNoErrors();
        $p = Payment::first();
        $this->assertSame('TRY', $p->currency);
        $this->assertSame(100.0, $p->amountInBase());
        $this->assertSame(4000.0, (float) $p->allocations()->first()->payment_amount);
        $this->assertSame(0.0, $inv->fresh()->balance());

        $this->getJson('/finance/convert?amount=10&from=USD&to=TRY')->assertJson(['amount' => 400]);
        $this->receive($client, $try, 10, [], ['date' => today()->addDay()->toDateString()])->assertSessionHasErrors('date');
        // A receipt for another client cannot settle this client's invoice.
        $other = Client::factory()->create();
        $inv2 = $this->invoice($client, 50);
        $this->receive($other, $try, 2000, [['invoice_id' => $inv2->id, 'amount' => 50]])->assertSessionHasErrors('allocations');
    }

    public function test_contract_instalment_follows_payment(): void
    {
        $u = $this->finance();
        $client = Client::factory()->create();
        $acc = $this->account();
        $this->actingAs($u)->post('/crm/contracts', [
            'type' => 'project', 'client_id' => $client->id, 'title' => 'حملة', 'currency' => 'USD', 'value' => 1000,
            'start_date' => now()->toDateString(),
            'schedule' => [['kind' => 'payment', 'title' => 'كاملة', 'due_date' => now()->toDateString(), 'amount' => 1000]],
        ])->assertRedirect();
        $c = \App\Models\Contract::latest('id')->first();
        $this->post("/crm/contracts/{$c->id}/issue");
        $this->post("/crm/contracts/{$c->id}/sign", ['signed_on' => now()->toDateString(), 'client_signatory' => 'أحمد']);
        $s = $c->schedules()->first();
        $line = \App\Services\Finance\Invoices::scheduleLine($c->fresh(), $s);
        $inv = $this->invoice($client, 0, ['contract_id' => $c->id, 'items' => [$line]]);

        $this->receive($client, $acc, 1000, [['invoice_id' => $inv->id, 'amount' => 1000]])->assertSessionHasNoErrors();
        $this->assertSame('done', $s->fresh()->status);
        $this->post('/finance/payments/'.Payment::first()->id.'/reverse', ['reason' => 'x']);
        $this->assertSame('pending', $s->fresh()->status);
    }

    public function test_accounts_balances_transfers_and_scope(): void
    {
        $u = $this->finance();
        $this->actingAs($u);
        $this->post('/finance/accounts', ['name' => 'البنك', 'type' => 'bank', 'currency' => 'USD', 'opening_balance' => 1000, 'opening_date' => today()->subMonth()->toDateString()])->assertSessionHasNoErrors();
        $this->post('/finance/accounts', ['name' => 'صندوق الليرة', 'type' => 'cash', 'currency' => 'TRY'])->assertSessionHasNoErrors();
        [$bank, $cash] = Account::orderBy('id')->get()->all();

        $this->post('/finance/transfers', ['from_account_id' => $bank->id, 'to_account_id' => $cash->id, 'date' => today()->toDateString(), 'amount' => 100])->assertSessionHasErrors('to_amount');
        $this->post('/finance/transfers', ['from_account_id' => $bank->id, 'to_account_id' => $cash->id, 'date' => today()->toDateString(), 'amount' => 100, 'to_amount' => 3950])->assertSessionHasNoErrors();
        $this->assertSame(900.0, Accounts::balance($bank));
        $this->assertSame(3950.0, Accounts::balance($cash));

        $this->get('/finance/accounts')->assertOk()->assertInertia(fn (Assert $a) => $a->component('finance/accounts/Index')->has('accounts', 2)->has('transfers', 1));
        $this->get("/finance/accounts/{$bank->id}")->assertOk()->assertInertia(fn (Assert $a) => $a->where('statement.opening', 1000)->where('statement.closing', 900)->has('statement.rows', 1));

        // Currency of an account with movements is locked.
        $this->put("/finance/accounts/{$cash->id}", ['name' => 'x', 'type' => 'cash', 'currency' => 'USD'])->assertSessionHasErrors('currency');

        $t = \App\Models\AccountTransfer::first();
        $this->post("/finance/transfers/{$t->id}/reverse")->assertSessionHasNoErrors();
        $this->assertSame(1000.0, Accounts::balance($bank));
        $this->assertSame(0.0, Accounts::balance($cash));

        $this->delete("/finance/accounts/{$cash->id}")->assertSessionHasNoErrors();
        $this->assertSoftDeleted($cash);

        // Accounts need scope "all".
        $own = $this->finance('own');
        $this->actingAs($own)->get('/finance/accounts')->assertForbidden();
        $this->actingAs($own)->post('/finance/accounts', ['name' => 'x', 'type' => 'cash', 'currency' => 'USD'])->assertForbidden();
    }

    public function test_receivables_aging_statement_and_overdue_reminders(): void
    {
        $u = $this->finance();
        $client = Client::factory()->create();
        $acc = $this->account();
        $this->actingAs($u);
        $late = $this->invoice($client, 500, ['due_date' => today()->subDays(7)->toDateString(), 'issue_date' => today()->subDays(20)->toDateString()]);
        $current = $this->invoice($client, 200);
        $this->receive($client, $acc, 100, [['invoice_id' => $late->id, 'amount' => 100]]);

        $this->get('/finance/receivables')->assertOk()->assertInertia(fn (Assert $a) => $a->component('finance/receivables/Index')
            ->where('totals.total', 600)->where('totals.1_30', 400)->where('totals.current', 200)->has('rows', 1));

        $this->get("/finance/statements/{$client->id}")->assertOk()->assertInertia(fn (Assert $a) => $a->component('finance/receivables/Statement')
            ->where('statement.closing', 600)->where('statement.debit', 700)->where('statement.credit', 100)->has('statement.rows', 3));
        $this->get("/finance/statements/{$client->id}/xlsx")->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        // Statement needs scope all.
        $this->actingAs($this->finance('own'))->get("/finance/statements/{$client->id}")->assertForbidden();

        // Reminder on day 7, once per day.
        $this->assertSame(1, Receivables::remindOverdue());
        $this->assertSame(0, Receivables::remindOverdue());
        $this->assertSame(1, $u->notifications()->where('data->kind', 'finance.invoice_overdue')->count());
        $this->assertSame($current->id, Invoice::withPaid()->unpaid()->whereDate('due_date', '>=', today())->value('id'));
    }

    public function test_receipt_pdf_renders(): void
    {
        $u = $this->finance();
        $client = Client::factory()->create();
        $acc = $this->account();
        $this->actingAs($u);
        $inv = $this->invoice($client, 120);
        $this->receive($client, $acc, 120, [['invoice_id' => $inv->id, 'amount' => 120]]);
        $res = $this->get('/finance/payments/'.Payment::first()->id.'/pdf')->assertOk();
        $this->assertStringStartsWith('%PDF', $res->getContent());
    }
}
