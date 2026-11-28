<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An invoice gets its number when issued; issued invoices are locked and cancelled, never deleted.
        // Paid / partly paid / overdue are derived from payments (phase 4, step 2) and the due date.
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->nullable()->unique();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('quote_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('status', 16)->default('draft'); // draft | issued | cancelled
            $table->date('issue_date')->nullable();
            $table->date('due_date')->nullable();
            $table->string('currency', 3)->default('USD');
            $table->decimal('fx_rate', 18, 6)->nullable(); // 1 USD = fx_rate units, fixed at issue
            $table->decimal('total', 14, 2)->nullable(); // frozen at issue (lines are locked from then on)
            $table->text('intro')->nullable();
            $table->text('terms')->nullable();
            $table->text('notes')->nullable();
            $table->text('payment_details')->nullable(); // bank / transfer details printed on the PDF
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->foreignId('source_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'due_date']);
            $table->index(['client_id', 'status']);
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('service_category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contract_schedule_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->text('details')->nullable();
            $table->decimal('quantity', 10, 2)->default(1);
            $table->string('unit', 32)->nullable();
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0); // from the category; frozen at issue
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        // Billable time that went into an invoice line (so it is billed once).
        Schema::table('time_entries', function (Blueprint $table) {
            $table->foreignId('invoice_item_id')->nullable()->after('billable')->constrained('invoice_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invoice_item_id');
        });
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
    }
};
