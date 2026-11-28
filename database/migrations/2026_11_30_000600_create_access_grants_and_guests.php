<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Guests (clients, freelancers): see only what is shared with them.
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_guest')->default(false)->after('is_super_admin');
        });

        // Access to a client, project or list's tasks beyond the person's own permission scope.
        Schema::create('access_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('scope_type', 16); // client | project | list
            $table->unsignedBigInteger('scope_id');
            $table->string('level', 8)->default('view'); // view (and comment) | edit (and create)
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'scope_type', 'scope_id']);
            $table->index(['scope_type', 'scope_id']);
        });

        // Internal tasks and comments are never shown to guests.
        Schema::table('tasks', function (Blueprint $table) {
            $table->boolean('is_internal')->default(false)->after('priority');
        });
        Schema::table('comments', function (Blueprint $table) {
            $table->boolean('is_internal')->default(false)->after('body_text');
        });
    }

    public function down(): void
    {
        Schema::table('comments', fn (Blueprint $t) => $t->dropColumn('is_internal'));
        Schema::table('tasks', fn (Blueprint $t) => $t->dropColumn('is_internal'));
        Schema::dropIfExists('access_grants');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('is_guest'));
    }
};
