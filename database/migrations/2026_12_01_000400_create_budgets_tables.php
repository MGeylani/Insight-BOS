<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Internal departments / activities costs are tracked against (production, marketing, admin…).
        Schema::create('cost_centers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('cost_center_id')->nullable()->after('client_id')->constrained()->nullOnDelete();
        });

        // A planned spend for a project, a cost center or the whole company, over a period, in USD.
        // A project may have several (per phase, per campaign).
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('cost_center_id')->nullable()->constrained()->nullOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedTinyInteger('alert_percent')->default(90); // warn when a line reaches this share of its plan
            $table->text('notes')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // kind: category (approved expenses of that category) | labor (logged hours × hourly cost).
        Schema::create('budget_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16)->default('category');
            $table->foreignId('category_id')->nullable()->constrained('expense_categories')->nullOnDelete();
            $table->decimal('planned', 14, 2); // USD
            $table->unsignedSmallInteger('alert_level')->default(0); // highest alert already sent: 0, alert_percent, 100
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_lines');
        Schema::dropIfExists('budgets');
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cost_center_id');
        });
        Schema::dropIfExists('cost_centers');
    }
};
