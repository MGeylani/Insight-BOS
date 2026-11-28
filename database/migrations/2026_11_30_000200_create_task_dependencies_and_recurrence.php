<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // type = waiting: task_id waits on depends_on_id (depends_on_id blocks task_id).
        // type = link: a plain relation between two tasks (stored once, read both ways).
        Schema::create('task_dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('depends_on_id')->constrained('tasks')->cascadeOnDelete();
            $table->string('type', 8)->default('waiting');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['task_id', 'depends_on_id', 'type']);
            $table->index('depends_on_id');
        });

        // recurrence (JSON): freq daily|weekly|monthly|yearly, interval, weekdays (0=Sun..6), on complete|schedule, until.
        Schema::table('tasks', function (Blueprint $table) {
            $table->json('recurrence')->nullable()->after('time_estimate_min');
            $table->foreignId('recurred_from_id')->nullable()->after('recurrence')->constrained('tasks')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recurred_from_id');
            $table->dropColumn('recurrence');
        });
        Schema::dropIfExists('task_dependencies');
    }
};
