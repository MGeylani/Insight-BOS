<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Capacity for the workload view: hours a day on the person's working days (null = config default).
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('capacity_hours', 4, 1)->default(8)->after('hourly_cost');
            $table->json('work_days')->nullable()->after('capacity_hours'); // 0 = Sunday … 6 = Saturday
        });

        // Days off that remove capacity. Kept simple until the HR module (phase 5) brings leave requests.
        Schema::create('time_off', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('type', 16)->default('vacation'); // vacation | sick | other
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'starts_on', 'ends_on']);
        });

        // A person's week of time (Saturday to Friday): draft -> submitted -> approved / rejected.
        // Submitted and approved weeks are locked; billing uses approved weeks.
        Schema::create('timesheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('week_start');
            $table->string('status', 12)->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'week_start']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timesheets');
        Schema::dropIfExists('time_off');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['capacity_hours', 'work_days']));
    }
};
