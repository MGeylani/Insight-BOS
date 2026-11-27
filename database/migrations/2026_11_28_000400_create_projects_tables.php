<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A project belongs to a client and may come from an accepted quote or a signed contract.
        // `budget` is the agreed project value (what the client pays); costs arrive with time tracking
        // and expenses in later phases. "overdue" is derived (open and past due_date), never stored.
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->nullable()->unique(); // PRJ-2026-0001, given at creation
            $table->string('name');
            $table->string('type', 24); // config('projects.types')
            $table->string('template', 24)->nullable(); // template applied at creation
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('quote_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16)->default('planning'); // planning | active | on_hold | completed | cancelled
            $table->string('currency', 3)->default('USD');
            $table->decimal('budget', 14, 2)->nullable();
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'due_date']);
        });

        Schema::create('project_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 64)->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'user_id']);
        });

        // Delivery stages. Done by hand for now; from phase 3a their progress also comes from tasks.
        Schema::create('project_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('details')->nullable();
            $table->date('due_date')->nullable();
            $table->string('status', 16)->default('pending'); // pending | done
            $table->timestamp('done_at')->nullable();
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->index(['status', 'due_date']);
        });

        // What might go wrong (risk) vs. what is going wrong now (issue).
        Schema::create('project_risks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('category', 24)->default('other');
            $table->unsignedTinyInteger('probability')->default(2); // 1-3
            $table->unsignedTinyInteger('impact')->default(2); // 1-3
            $table->text('mitigation')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16)->default('open'); // open | mitigated | occurred | closed
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('project_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('severity', 16)->default('medium'); // low | medium | high | critical
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->string('status', 16)->default('open'); // open | in_progress | resolved
            $table->text('resolution')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'due_date']);
        });

        // Approving a change request adds its cost impact to the budget and its days to the due date.
        Schema::create('change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('seq'); // CR-1, CR-2... within the project
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('source', 16)->default('client'); // client | internal
            $table->decimal('cost_impact', 14, 2)->default(0); // in the project currency; may be negative
            $table->integer('days_impact')->default(0); // may be negative
            $table->string('status', 16)->default('pending'); // pending | approved | rejected
            $table->text('decision_note')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->decimal('budget_before', 14, 2)->nullable(); // snapshot at approval
            $table->date('due_before')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['project_id', 'seq']);
        });

        // Project numbers (also added by NumberSequenceSeeder; inserted here so `migrate` alone is enough).
        if (Schema::hasTable('number_sequences') && ! DB::table('number_sequences')->where('type', 'project')->exists()) {
            DB::table('number_sequences')->insert([
                'type' => 'project', 'label' => 'المشاريع', 'prefix' => 'PRJ', 'padding' => 4, 'yearly_reset' => true,
                'last_number' => 0, 'current_year' => (int) date('Y'), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('change_requests');
        Schema::dropIfExists('project_issues');
        Schema::dropIfExists('project_risks');
        Schema::dropIfExists('project_milestones');
        Schema::dropIfExists('project_members');
        Schema::dropIfExists('projects');
        DB::table('number_sequences')->where('type', 'project')->where('last_number', 0)->delete();
    }
};
