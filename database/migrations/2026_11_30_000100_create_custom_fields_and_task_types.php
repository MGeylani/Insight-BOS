<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A task type gives a task its icon and (through task_type_fields) the custom fields that belong to it.
        Schema::create('task_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64);
            $table->string('icon', 32)->default('circle-check'); // lucide icon name
            $table->string('color', 16)->default('#0082F6');
            $table->boolean('is_milestone')->default(false);
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        // Scope: everywhere (all) or one client / project / list. Fields linked to task types only show on
        // tasks of those types. options (JSON) by type: choices [{id,label,color}], currency, max, decimals, formula.
        Schema::create('custom_fields', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('type', 16);
            $table->json('options')->nullable();
            $table->string('scope_type', 16)->default('all'); // all | client | project | list
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['scope_type', 'scope_id']);
        });

        Schema::create('task_type_fields', function (Blueprint $table) {
            $table->foreignId('task_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('custom_field_id')->constrained()->cascadeOnDelete();
            $table->primary(['task_type_id', 'custom_field_id']);
        });

        Schema::create('custom_field_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('custom_field_id')->constrained()->cascadeOnDelete();
            $table->json('value');
            $table->string('sort_value', 191)->nullable(); // text / number padded, for filters and sorting
            $table->timestamps();
            $table->unique(['task_id', 'custom_field_id']);
            $table->index(['custom_field_id', 'sort_value']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('task_type_id')->nullable()->after('parent_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('task_type_id');
        });
        Schema::dropIfExists('custom_field_values');
        Schema::dropIfExists('task_type_fields');
        Schema::dropIfExists('custom_fields');
        Schema::dropIfExists('task_types');
    }
};
