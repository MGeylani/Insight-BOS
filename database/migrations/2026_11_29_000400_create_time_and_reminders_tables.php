<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Internal cost of one hour of the person's work (USD). Visible only with hr.view_salaries.
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('hourly_cost', 10, 2)->nullable()->after('phone');
        });

        // One table for the timer and manual entries. A running timer has ended_at = null (one per person).
        // cost_rate is the person's hourly cost when the entry was recorded, so later rate changes do not
        // rewrite past project costs. project_id / client_id are copied from the task for reporting.
        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
            $table->unsignedInteger('minutes')->default(0);
            $table->boolean('billable')->default(true);
            $table->decimal('cost_rate', 10, 2)->nullable();
            $table->string('note', 500)->nullable();
            $table->string('source', 8)->default('manual'); // timer | manual
            $table->timestamps();
            $table->index(['user_id', 'started_at']);
            $table->index(['project_id', 'billable']);
        });

        // Personal reminders, sent as in-app notifications by `tasks:reminders` (scheduler, every minute).
        Schema::create('reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->cascadeOnDelete();
            $table->dateTime('remind_at');
            $table->string('note', 255)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->timestamps();
            $table->index(['sent_at', 'remind_at']);
            $table->index(['user_id', 'done_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminders');
        Schema::dropIfExists('time_entries');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('hourly_cost'));
    }
};
