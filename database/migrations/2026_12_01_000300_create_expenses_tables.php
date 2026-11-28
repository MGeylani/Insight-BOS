<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // direct = cost of delivering client work (production, printing, ads, freelancers); overhead = running the agency.
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('kind', 16)->default('overhead'); // direct | overhead
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 16)->default('company'); // company | freelancer
            $table->string('phone', 40)->nullable();
            $table->string('email')->nullable();
            $table->string('tax_number', 60)->nullable();
            $table->text('payment_details')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // Money handed to an employee: petty cash (settled by expenses) or a salary advance (paid back).
        Schema::create('advances', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->string('kind', 16)->default('petty_cash'); // petty_cash | salary_advance
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3);
            $table->decimal('fx_rate', 18, 6);
            $table->text('notes')->nullable();
            $table->string('status', 16)->default('posted'); // posted | reversed
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // What came back from an advance: cash into an account, or deducted (salary) with no account.
        Schema::create('advance_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('advance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('date');
            $table->decimal('amount', 14, 2); // in the advance's currency
            $table->string('notes', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Paying back employees who spent their own money, one payout for many expenses.
        Schema::create('reimbursements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3);
            $table->decimal('fx_rate', 18, 6);
            $table->string('reference', 120)->nullable();
            $table->string('status', 16)->default('posted'); // posted | reversed
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // One row per cost. paid_by: account (paid now) | employee (out of pocket → reimbursement) |
        // advance (from petty cash / an advance) | credit (vendor bill, paid later by vendor payments).
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->date('date');
            $table->foreignId('category_id')->constrained('expense_categories')->restrictOnDelete();
            $table->string('description', 255);
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3);
            $table->decimal('fx_rate', 18, 6);
            $table->string('paid_by', 16);
            $table->foreignId('account_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('advance_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('vendor_ref', 80)->nullable(); // the vendor's own invoice number
            $table->date('due_date')->nullable(); // credit only
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete(); // who incurred it
            $table->string('status', 16)->default('pending'); // pending | approved | rejected | cancelled
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->foreignId('reimbursement_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'date']);
            $table->index(['project_id', 'status']);
            $table->index(['user_id', 'paid_by', 'status']);
        });

        Schema::create('vendor_payments', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3);
            $table->decimal('fx_rate', 18, 6);
            $table->string('method', 16);
            $table->string('reference', 120)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 16)->default('posted'); // posted | reversed
            $table->timestamp('reversed_at')->nullable();
            $table->text('reverse_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // `amount` in the bill's currency, `payment_amount` in the payment's.
        Schema::create('vendor_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expense_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->decimal('payment_amount', 14, 2);
            $table->timestamps();
            $table->index('expense_id');
        });

        foreach ([['advance', 'السلف والعهد', 'ADV'], ['vendor_payment', 'دفعات الموردين', 'VP']] as [$type, $label, $prefix]) {
            if (Schema::hasTable('number_sequences') && ! DB::table('number_sequences')->where('type', $type)->exists()) {
                DB::table('number_sequences')->insert([
                    'type' => $type, 'label' => $label, 'prefix' => $prefix, 'padding' => 4, 'yearly_reset' => true,
                    'last_number' => 0, 'current_year' => (int) date('Y'), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        foreach (['vendor_payment_allocations', 'vendor_payments', 'expenses', 'reimbursements', 'advance_returns', 'advances', 'vendors', 'expense_categories'] as $t) {
            Schema::dropIfExists($t);
        }
        DB::table('number_sequences')->whereIn('type', ['advance', 'vendor_payment'])->where('last_number', 0)->delete();
    }
};
