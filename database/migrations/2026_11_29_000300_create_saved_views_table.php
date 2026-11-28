<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A named view of tasks: its type (list / board / calendar / table) and config (filters, grouping,
        // sorting, columns). Scoped to the whole company (location null) or to a client / project / list.
        Schema::create('saved_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('type', 16); // list | board | calendar | table
            $table->string('location_type', 16)->nullable(); // client | project | list
            $table->unsignedBigInteger('location_id')->nullable();
            $table->json('config');
            $table->boolean('is_shared')->default(false);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->index(['location_type', 'location_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_views');
    }
};
