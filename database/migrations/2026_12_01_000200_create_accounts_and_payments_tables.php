<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cash boxes and bank accounts. The balance is computed from the opening balance and every movement.
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 16); // cash | bank | wallet
            $table->string('currency', 3);
            $table->decimal('opening_balance', 14, 2)->default(0);
            $table->date('opening_date')->nullable();
            $table->text('details')->nullable(); // bank name, branch, notes (shown to finance only)
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // Money moved between two accounts. Across currencies both amounts are entered (what left, what arrived).
        Schema::create('account_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('to_account_id')->constrained('accounts')->restrictOnDelete();
            $table->date('date');
            $table->decimal('amount', 14, 2); // in the source account's currency
            $table->decimal('to_amount', 14, 2); // in the destination account's currency
            $table->string('reference', 120)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['date']);
        });

        // Receipts (money in from a client) and refunds (money back to the client). Numbered at creation (RCPT-…),
        // never deleted: a mistake is reversed. Amount is in the account's currency.
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->nullable()->unique();
            $table->string('kind', 16)->default('receipt'); // receipt | refund
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('refund_of_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->date('date');
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3);
            $table->decimal('fx_rate', 18, 6); // 1 USD = fx_rate units on the payment date
            $table->string('method', 16); // cash | transfer | card | cheque | wallet | other
            $table->string('reference', 120)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 16)->default('posted'); // posted | reversed
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reverse_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['client_id', 'status']);
            $table->index(['account_id', 'date']);
        });

        // How a receipt settles invoices: one receipt → many invoices, one invoice ← many receipts.
        // `amount` is in the invoice's currency, `payment_amount` the part of the receipt (its currency) it used.
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->decimal('payment_amount', 14, 2);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('invoice_id');
        });

        // When an overdue reminder was last sent for an invoice (finance:overdue).
        Schema::table('invoices', function (Blueprint $table) {
            $table->date('reminded_on')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn('reminded_on'));
        foreach (['payment_allocations', 'payments', 'account_transfers', 'accounts'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
