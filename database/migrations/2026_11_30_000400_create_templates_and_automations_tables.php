<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A saved task (with subtasks and checklists), list (statuses + tasks) or project (several lists).
        // Dates are stored relative to the day the template is applied (due_in_days).
        Schema::create('task_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('type', 8); // task | list | project
            $table->json('payload');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // trigger -> conditions -> actions, scoped like custom fields (all / client / project / list).
        Schema::create('automations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('scope_type', 16)->default('all');
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->string('trigger', 32);
            $table->json('trigger_config')->nullable();
            $table->json('conditions')->nullable();
            $table->json('actions');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('runs_count')->default(0);
            $table->timestamp('last_run_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['trigger', 'is_active']);
        });

        Schema::create('automation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 8); // ok | error | skipped
            $table->string('message', 500)->nullable();
            $table->string('dedupe_key', 120)->nullable(); // time-based triggers fire once per key
            $table->timestamp('created_at')->nullable();
            $table->index(['automation_id', 'dedupe_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_runs');
        Schema::dropIfExists('automations');
        Schema::dropIfExists('task_templates');
    }
};
