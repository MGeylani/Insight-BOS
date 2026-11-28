<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Advance;
use App\Models\Attachment;
use App\Models\ExchangeRate;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Project;
use App\Models\Reimbursement;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorPayment;
use App\Services\Finance\Accounts;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\ExpenseCategorySeeder;
use Database\Seeders\NumberSequenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ExpenseTest extends TestCase
{
    use RefreshDatabase;

    private ExpenseCategory $cat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CurrencySeeder::class, NumberSequenceSeeder::class, ExpenseCategorySeeder::class]);
        ExchangeRate::create(['currency_code' => 'TRY', 'date' => today(), 'rate' => 40]);
        $this->cat = ExpenseCategory::where('name', 'طباعة')->first();
    }

    private function finance(): User
    {
        return $this->userWith(array_fill_keys(['finance.view', 'finance.create', 'finance.edit', 'finance.delete', 'finance.approve', 'finance.expense'], 'all'));
    }

    private function employee(): User
    {
        return $this->userWith(['finance.expense' => 'own']);
    }

    private function account(string $currency = 'USD', float $opening = 1000): Account
    {
        return Account::create(['name' => "صندوق $currency", 'type' => 'cash', 'currency' => $currency, 'opening_balance' => $opening]);
    }

    private function submit(array $extra = [])
    {
        return $this->post('/finance/expenses', $extra + [
            'date' => today()->toDateString(), 'category_id' => $this->cat->id, 'description' => 'طباعة رول أب',
            'amount' => 120, 'currency' => 'USD', 'paid_by' => 'employee',
        ]);
    }

    private function receipt(Expense $e): void
    {
        Attachment::forceCreate(['attachable_type' => 'expense', 'attachable_id' => $e->id, 'name' => 'receipt.jpg', 'size' => 10, 'mime' => 'image/jpeg', 'status' => 'uploaded']);
    }

    public function test_employee_expense_needs_receipt_approval_and_reimbursement(): void
    {
        $emp = $this->employee();
        $fin = $this->finance();
        $acc = $this->account();

        $this->actingAs($emp)->submit()->assertSessionHasNoErrors();
        $e = Expense::first();
        $this->assertSame('EXP-'.now()->year.'-0001', $e->number);
        $this->assertSame('pending', $e->status);
        $this->assertSame($emp->id, $e->user_id);
        // Approvers are told.
        $this->assertSame(1, $fin->notifications()->where('data->kind', 'finance.expense_submitted')->count());

        // Nobody approves their own expense; the employee cannot approve at all.
        $this->post("/finance/expenses/{$e->id}/approve")->assertForbidden();

        // No receipt → refused.
        $this->actingAs($fin)->post("/finance/expenses/{$e->id}/approve")->assertSessionHasErrors('receipt');
        $this->receipt($e);
        $this->post("/finance/expenses/{$e->id}/approve")->assertSessionHasNoErrors();
        $e->refresh();
        $this->assertSame('approved', $e->status);
        $this->assertSame('to_reimburse', $e->settlement());
        $this->assertSame(1, $emp->notifications()->where('data->kind', 'finance.expense_decided')->count());
        // Out of pocket: the account is untouched until the reimbursement.
        $this->assertSame(1000.0, Accounts::balance($acc));

        $this->get('/finance/reimbursements')->assertOk()->assertInertia(fn (Assert $a) => $a->has('groups', 1)->where('groups.0.total', 120));
        $this->post('/finance/reimbursements', ['user_id' => $emp->id, 'account_id' => $acc->id, 'date' => today()->toDateString(), 'expense_ids' => [$e->id]])->assertSessionHasNoErrors();
        $this->assertSame('reimbursed', $e->fresh()->settlement());
        $this->assertSame(880.0, Accounts::balance($acc));
        // A reimbursed expense cannot be cancelled.
        $this->post("/finance/expenses/{$e->id}/cancel", ['reason' => 'x'])->assertForbidden();

        $r = Reimbursement::first();
        $this->post("/finance/reimbursements/{$r->id}/reverse")->assertSessionHasNoErrors();
        $this->assertSame(1000.0, Accounts::balance($acc));
        $this->assertSame('to_reimburse', $e->fresh()->settlement());
        // Wrong currency account is refused.
        $try = $this->account('TRY');
        $this->post('/finance/reimbursements', ['user_id' => $emp->id, 'account_id' => $try->id, 'date' => today()->toDateString(), 'expense_ids' => [$e->id]])->assertSessionHasErrors('account_id');
    }

    public function test_account_expense_hits_balance_only_when_approved(): void
    {
        $fin = $this->finance();
        $other = $this->finance();
        $try = $this->account('TRY', 10000);
        $this->actingAs($fin)->submit(['paid_by' => 'account', 'account_id' => $try->id, 'amount' => 800, 'currency' => 'USD'])->assertSessionHasNoErrors();
        $e = Expense::first();
        $this->assertSame('TRY', $e->currency); // follows the account
        $this->assertSame(20.0, $e->amountInBase());
        $this->assertSame(10000.0, Accounts::balance($try));

        $this->receipt($e);
        $this->post("/finance/expenses/{$e->id}/approve")->assertForbidden(); // own
        $this->actingAs($other)->post("/finance/expenses/{$e->id}/approve")->assertSessionHasNoErrors();
        $this->assertSame(9200.0, Accounts::balance($try));
        $this->get("/finance/accounts/{$try->id}")->assertInertia(fn (Assert $a) => $a->has('statement.rows', 1)->where('statement.rows.0.kind', 'expense'));

        // Approved and unsettled: finance can cancel with a reason; it leaves the balance.
        $this->post("/finance/expenses/{$e->id}/cancel", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post("/finance/expenses/{$e->id}/cancel", ['reason' => 'مكرر'])->assertSessionHasNoErrors();
        $this->assertSame(10000.0, Accounts::balance($try));

        // Future dates are refused; edits only while pending.
        $this->submit(['date' => today()->addDay()->toDateString()])->assertSessionHasErrors('date');
        $this->put("/finance/expenses/{$e->id}", ['date' => today()->toDateString(), 'category_id' => $this->cat->id, 'description' => 'x', 'amount' => 1, 'currency' => 'USD', 'paid_by' => 'employee'])->assertForbidden();
    }

    public function test_advance_settled_by_expenses_and_returns(): void
    {
        $fin = $this->finance();
        $emp = $this->employee();
        $acc = $this->account('USD', 500);
        $this->actingAs($fin)->post('/finance/advances', ['user_id' => $emp->id, 'kind' => 'petty_cash', 'account_id' => $acc->id, 'date' => today()->toDateString(), 'amount' => 200])->assertSessionHasNoErrors();
        $adv = Advance::first();
        $this->assertSame(300.0, Accounts::balance($acc));

        // The employee spends from it and sees their own advance.
        $this->actingAs($emp)->get('/finance/advances')->assertInertia(fn (Assert $a) => $a->has('advances', 1));
        $this->get("/finance/advances/{$adv->id}")->assertOk();
        $this->submit(['paid_by' => 'advance', 'advance_id' => $adv->id, 'amount' => 150])->assertSessionHasNoErrors();
        $this->submit(['paid_by' => 'advance', 'advance_id' => $adv->id, 'amount' => 100])->assertSessionHasNoErrors();
        [$e1, $e2] = Expense::orderBy('id')->get()->all();
        $this->receipt($e1);
        $this->receipt($e2);

        $this->actingAs($fin)->post("/finance/expenses/{$e1->id}/approve")->assertSessionHasNoErrors();
        $this->assertSame(50.0, $adv->fresh()->balance());
        // More than what is left in the advance.
        $this->post("/finance/expenses/{$e2->id}/approve")->assertSessionHasErrors('advance_id');
        $this->post("/finance/expenses/{$e2->id}/reject", ['reason' => 'سجّله من مالك'])->assertSessionHasNoErrors();

        // Account balance is unchanged by advance expenses; the cash return comes back in.
        $this->assertSame(300.0, Accounts::balance($acc));
        $this->post("/finance/advances/{$adv->id}/return", ['amount' => 60, 'account_id' => $acc->id, 'date' => today()->toDateString()])->assertSessionHasErrors('amount');
        $this->post("/finance/advances/{$adv->id}/return", ['amount' => 50, 'account_id' => $acc->id, 'date' => today()->toDateString()])->assertSessionHasNoErrors();
        $this->assertSame(0.0, $adv->fresh()->balance());
        $this->assertSame(350.0, Accounts::balance($acc));
        $this->post("/finance/advances/{$adv->id}/reverse")->assertSessionHasErrors('advance');

        // Other employees cannot open it.
        $this->actingAs($this->employee())->get("/finance/advances/{$adv->id}")->assertForbidden();
    }

    public function test_vendor_bills_and_payments(): void
    {
        $fin = $this->finance();
        $approver = $this->finance();
        $acc = $this->account('USD', 2000);
        $this->actingAs($fin)->post('/finance/vendors', ['name' => 'مطبعة النور', 'type' => 'company'])->assertSessionHasNoErrors();
        $v = Vendor::first();

        $this->submit(['paid_by' => 'credit'])->assertSessionHasErrors('vendor_id');
        $this->submit(['paid_by' => 'credit', 'vendor_id' => $v->id, 'vendor_ref' => 'A-77', 'amount' => 300])->assertSessionHasNoErrors();
        $this->submit(['paid_by' => 'credit', 'vendor_id' => $v->id, 'amount' => 200, 'due_date' => today()->addDays(5)->toDateString()])->assertSessionHasNoErrors();
        [$b1, $b2] = Expense::orderBy('id')->get()->all();
        $this->assertSame(today()->addDays(30)->toDateString(), $b1->due_date->toDateString());
        foreach ([$b1, $b2] as $b) {
            $this->receipt($b);
            $this->actingAs($approver)->post("/finance/expenses/{$b->id}/approve")->assertSessionHasNoErrors();
        }
        $this->assertSame(2000.0, Accounts::balance($acc)); // credit: nothing paid yet
        $this->get('/finance/vendors')->assertInertia(fn (Assert $a) => $a->where('total_owed_usd', 500)->where('vendors.0.owed_usd', 500));

        // Pay 400: settles the first bill, part of the second.
        $this->post("/finance/vendors/{$v->id}/pay", [
            'account_id' => $acc->id, 'date' => today()->toDateString(), 'amount' => 400, 'method' => 'transfer',
            'allocations' => [['expense_id' => $b1->id, 'amount' => 300], ['expense_id' => $b2->id, 'amount' => 100]],
        ])->assertSessionHasNoErrors();
        $p = VendorPayment::first();
        $this->assertSame('VP-'.now()->year.'-0001', $p->number);
        $this->assertSame('paid', $b1->fresh()->settlement());
        $this->assertSame('partial', $b2->fresh()->settlement());
        $this->assertSame(1600.0, Accounts::balance($acc));
        $this->get("/finance/vendors/{$v->id}")->assertOk()->assertInertia(fn (Assert $a) => $a->has('openBills', 1)->where('vendor.owed_usd', 100));

        // Over-paying a bill is refused.
        $this->post("/finance/vendors/{$v->id}/pay", ['account_id' => $acc->id, 'date' => today()->toDateString(), 'amount' => 500, 'method' => 'cash',
            'allocations' => [['expense_id' => $b2->id, 'amount' => 500]]])->assertSessionHasErrors('allocations');
        // A settled bill cannot be cancelled.
        $this->post("/finance/expenses/{$b1->id}/cancel", ['reason' => 'x'])->assertSessionHasErrors('status');

        $this->post("/finance/vendor-payments/{$p->id}/reverse", ['reason' => 'خطأ'])->assertSessionHasNoErrors();
        $this->assertSame(2000.0, Accounts::balance($acc));
        $this->assertSame('unpaid', $b1->fresh()->settlement());
    }

    public function test_visibility_and_project_manager_approval(): void
    {
        $pm = $this->userWith(['finance.expense' => 'own', 'finance.approve' => 'assigned']);
        $emp = $this->employee();
        $other = $this->employee();
        $project = Project::factory()->create(['manager_id' => $pm->id]);

        $this->actingAs($emp)->submit(['project_id' => $project->id])->assertSessionHasNoErrors();
        $this->submit()->assertSessionHasNoErrors();
        [$onProject, $plain] = Expense::orderBy('id')->get()->all();
        $this->assertSame($project->client_id, $onProject->client_id);
        $this->assertSame(1, $pm->notifications()->count()); // only the project one reaches the PM

        $this->actingAs($other)->get('/finance/expenses')->assertInertia(fn (Assert $a) => $a->has('expenses.data', 0));
        $this->get("/finance/expenses/{$plain->id}")->assertForbidden();
        $this->actingAs($emp)->get('/finance/expenses')->assertInertia(fn (Assert $a) => $a->has('expenses.data', 2));

        $this->actingAs($pm)->get('/finance/expenses')->assertInertia(fn (Assert $a) => $a->has('expenses.data', 1)->where('summary.to_decide', 1));
        $this->receipt($onProject);
        $this->receipt($plain);
        $this->post("/finance/expenses/{$plain->id}/approve")->assertForbidden();
        $this->post("/finance/expenses/{$onProject->id}/approve")->assertSessionHasNoErrors();

        // Employees without finance access cannot see finance pages, but can open the expense form.
        $this->actingAs($emp)->get('/finance/invoices')->assertForbidden();
        $this->get('/finance/vendors')->assertForbidden();
        $this->get('/finance/expenses/create')->assertOk()->assertInertia(fn (Assert $a) => $a->component('finance/expenses/Form')->has('people', 0));
        // Submitting "for someone else" is ignored without finance scope all.
        $this->submit(['user_id' => $other->id])->assertSessionHasNoErrors();
        $this->assertSame($emp->id, Expense::latest('id')->first()->user_id);
        // The submitter may edit and withdraw while pending.
        $mine = Expense::latest('id')->first();
        $this->get("/finance/expenses/{$mine->id}/edit")->assertOk();
        $this->post("/finance/expenses/{$mine->id}/cancel")->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $mine->fresh()->status);

        $nobody = $this->userWith(['tasks.view' => 'own']);
        $this->actingAs($nobody)->get('/finance/expenses')->assertForbidden();
    }

    public function test_pages_render(): void
    {
        $fin = $this->finance();
        $this->actingAs($fin);
        $this->get('/finance/expenses')->assertOk()->assertInertia(fn (Assert $a) => $a->component('finance/expenses/Index'));
        $this->get('/finance/expenses/create')->assertOk();
        $this->submit();
        $this->get('/finance/expenses/'.Expense::first()->id)->assertOk()->assertInertia(fn (Assert $a) => $a->component('finance/expenses/Show')->where('expense.has_receipt', false));
        $this->get('/finance/advances')->assertOk();
        $this->get('/finance/reimbursements')->assertOk();
        $this->post('/finance/expense-categories', ['name' => 'تجربة', 'kind' => 'direct'])->assertSessionHasNoErrors();
        $this->actingAs($this->employee())->post('/finance/expense-categories', ['name' => 'x', 'kind' => 'direct'])->assertForbidden();
    }
}
