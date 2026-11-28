<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A request form that creates a task in a list, with the fields the form asks for.
        Schema::create('intake_forms', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->foreignId('task_list_id')->constrained('task_lists')->cascadeOnDelete();
            $table->string('token', 40)->unique();
            $table->boolean('is_public')->default(false); // open without signing in (clients, the website)
            $table->boolean('is_active')->default(true);
            $table->json('fields');   // [{key, label, required, help}]
            $table->json('defaults')->nullable(); // assignees, type_id, tags, priority, due_in_days
            $table->string('confirmation', 500)->nullable();
            $table->unsignedInteger('submissions_count')->default(0);
            $table->timestamp('last_submitted_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Versions of a file: a new version points to the one it replaces.
        Schema::table('attachments', function (Blueprint $table) {
            $table->foreignId('previous_id')->nullable()->after('attachable_id')->constrained('attachments')->nullOnDelete();
            $table->unsignedSmallInteger('version')->default(1)->after('previous_id');
        });

        // Comments on a point of an image (x, y in %) or a moment of a video (at_seconds).
        Schema::create('proof_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attachment_id')->constrained('attachments')->cascadeOnDelete();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('proof_comments')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_name', 120)->nullable(); // for client comments from the portal later
            $table->text('body');
            $table->decimal('x', 6, 3)->nullable();
            $table->decimal('y', 6, 3)->nullable();
            $table->decimal('at_seconds', 10, 2)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['attachment_id', 'parent_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proof_comments');
        Schema::table('attachments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('previous_id');
            $table->dropColumn('version');
        });
        Schema::dropIfExists('intake_forms');
    }
};
