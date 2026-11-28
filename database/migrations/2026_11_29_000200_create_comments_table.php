<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Threaded comments on any record (tasks for now). A reply's parent is always a top-level comment.
        // An assigned comment shows in the assignee's "My Work" until it is resolved.
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->string('commentable_type', 32);
            $table->unsignedBigInteger('commentable_id');
            $table->foreignId('parent_id')->nullable()->constrained('comments')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->json('body');
            $table->text('body_text')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['commentable_type', 'commentable_id']);
            $table->index(['assigned_to', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};
