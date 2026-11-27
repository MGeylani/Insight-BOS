<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A contract gets its number when issued for signature. Issued contracts are locked; they are
        // cancelled or terminated, never deleted. Amounts are the agreed amounts (tax handled at invoicing, phase 4).
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->nullable()->unique();
            $table->string('type', 16); // project | retainer
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('opportunity_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('quote_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('status', 16)->default('draft'); // draft | sent | active | completed | terminated | cancelled
            $table->string('currency', 3)->default('USD');
            $table->decimal('fx_rate', 18, 6)->nullable(); // 1 USD = fx_rate units, fixed at issue
            $table->decimal('value', 14, 2)->nullable(); // project: total agreed value
            $table->decimal('monthly_fee', 14, 2)->nullable(); // retainer: fee per month
            $table->unsignedSmallInteger('term_months')->nullable(); // retainer: length of one term
            $table->string('billing_timing', 8)->nullable(); // retainer: advance | arrears
            $table->boolean('auto_renew')->default(false);
            $table->unsignedSmallInteger('notice_days')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable(); // retainer: set from start + terms
            $table->date('issue_date')->nullable();
            $table->text('scope')->nullable();
            $table->text('terms')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->date('signed_on')->nullable();
            $table->string('client_signatory', 190)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->date('terminated_on')->nullable();
            $table->text('termination_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('project_id')->nullable(); // linked when projects arrive (phase 2, step 5)
            $table->foreignId('source_contract_id')->nullable()->constrained('contracts')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'end_date']);
        });

        // Payment instalments and delivery milestones. Retainer payments are generated from the fee and term.
        Schema::create('contract_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16); // payment | milestone
            $table->string('title');
            $table->text('details')->nullable();
            $table->date('due_date')->nullable();
            $table->decimal('amount', 14, 2)->nullable(); // payments only
            $table->date('period_start')->nullable(); // retainer periods
            $table->date('period_end')->nullable();
            $table->string('status', 16)->default('pending'); // pending | done | cancelled
            $table->timestamp('done_at')->nullable();
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('invoice_id')->nullable(); // set by invoicing (phase 4)
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->index(['kind', 'status', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_schedules');
        Schema::dropIfExists('contracts');
    }
};
