<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every task gets a per-client id such as ULF-142: prefix + a counter kept on the client row.
        // The agency's own work lives under an internal client ("Insight Media", is_internal = true).
        Schema::table('clients', function (Blueprint $table) {
            $table->string('task_prefix', 8)->nullable()->unique()->after('currency');
            $table->unsignedInteger('task_seq')->default(0)->after('task_prefix');
            $table->boolean('is_internal')->default(false)->after('task_seq');
        });

        // A status scheme is the ordered set of statuses a list uses. Templates (is_template) are copied
        // into a list's own scheme when the list is created, so editing one list never affects another.
        Schema::create('status_schemes', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->boolean('is_template')->default(false);
            $table->boolean('is_default')->default(false); // the template new lists start from
            $table->timestamps();
        });

        Schema::create('task_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('status_scheme_id')->constrained()->cascadeOnDelete();
            $table->string('name', 64);
            $table->string('color', 16)->default('#94a3b8');
            $table->string('group', 16); // not_started | active | done | closed
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->index(['status_scheme_id', 'sort']);
        });

        // A list is a phase, campaign or month under a client, optionally inside one of its projects.
        // A list linked to a project milestone feeds that milestone's progress.
        Schema::create('task_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_milestone_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('status_scheme_id')->constrained()->restrictOnDelete();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['client_id', 'project_id']);
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 48)->unique();
            $table->string('color', 16)->default('#0082F6');
            $table->timestamps();
        });

        // tasks.task_list_id is the home list; task_list_links adds the task to other lists too.
        // client_id / project_id are copied from the home list so filters and costs stay simple.
        // Up to three levels: a task (depth 0), its subtasks (1) and theirs (2).
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_list_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_milestone_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('tasks')->cascadeOnDelete();
            $table->unsignedTinyInteger('depth')->default(0);
            $table->unsignedInteger('seq'); // ULF-<seq>
            $table->string('title', 255);
            $table->json('description')->nullable(); // rich text document (JSON)
            $table->text('description_text')->nullable(); // plain text of the description, for search
            $table->foreignId('status_id')->constrained('task_statuses')->restrictOnDelete();
            $table->string('priority', 8)->default('normal'); // urgent | high | normal | low
            $table->dateTime('start_at')->nullable();
            $table->boolean('start_has_time')->default(false);
            $table->dateTime('due_at')->nullable();
            $table->boolean('due_has_time')->default(false);
            $table->unsignedInteger('time_estimate_min')->nullable();
            $table->double('position')->default(0); // fractional ordering inside the home list
            $table->timestamp('completed_at')->nullable(); // set while the status is in the done / closed group
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['client_id', 'seq']);
            $table->index(['task_list_id', 'parent_id', 'position']);
            $table->index(['due_at', 'completed_at']);
        });

        Schema::create('task_list_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_list_id')->constrained()->cascadeOnDelete();
            $table->double('position')->default(0);
            $table->timestamps();
            $table->unique(['task_id', 'task_list_id']);
        });

        Schema::create('task_assignees', function (Blueprint $table) {
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['task_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('task_watchers', function (Blueprint $table) {
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['task_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('task_tag', function (Blueprint $table) {
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['task_id', 'tag_id']);
        });

        Schema::create('checklists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        // Items nest up to three levels (parent_id).
        Schema::create('checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checklist_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('checklist_items')->cascadeOnDelete();
            $table->string('text', 500);
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('done')->default(false);
            $table->timestamp('done_at')->nullable();
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['checklist_items', 'checklists', 'task_tag', 'task_watchers', 'task_assignees', 'task_list_links', 'tasks', 'tags', 'task_lists', 'task_statuses', 'status_schemes'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('clients', function (Blueprint $table) {
            $table->dropUnique(['task_prefix']);
            $table->dropColumn(['task_prefix', 'task_seq', 'is_internal']);
        });
    }
};
