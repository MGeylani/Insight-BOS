<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('type', 16)->default('company'); // config('crm.client_types')
            $table->string('industry', 120)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('phone_normalized', 32)->nullable()->index();
            $table->string('website')->nullable();
            $table->string('country', 80)->nullable();
            $table->string('city', 80)->nullable();
            $table->string('address')->nullable();
            $table->string('tax_number', 64)->nullable();
            $table->string('currency', 3)->default('USD');
            $table->string('source', 32)->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->string('storage_folder_id')->nullable(); // filled when storage is connected (step 6)
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index('name');
            $table->index('email');
        });

        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('job_title', 120)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('phone_normalized', 32)->nullable()->index();
            $table->string('whatsapp', 32)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index('email');
        });

        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // contact person
            $table->string('company_name')->nullable();
            $table->string('job_title', 120)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('phone_normalized', 32)->nullable()->index();
            $table->string('source', 32)->nullable();
            $table->string('stage', 16)->default('new'); // config('crm.lead_stages') + converted
            $table->string('rating', 8)->nullable(); // hot | warm | cold
            $table->decimal('expected_value', 14, 2)->nullable();
            $table->string('currency', 3)->default('USD');
            $table->string('interest')->nullable(); // what they asked about
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('position')->default(0); // order inside a Kanban column
            $table->text('notes')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete(); // set on conversion
            $table->foreignId('merged_into_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['stage', 'position']);
            $table->index('email');
        });

        Schema::create('opportunities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('stage', 16)->default('discovery');
            $table->unsignedTinyInteger('probability')->default(10);
            $table->decimal('amount', 14, 2)->default(0);
            $table->string('currency', 3)->default('USD');
            $table->date('expected_close_date')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('lost_reason', 32)->nullable();
            $table->text('lost_note')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['stage', 'expected_close_date']);
        });

        Schema::create('opportunity_stage_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opportunity_id')->constrained()->cascadeOnDelete();
            $table->string('from_stage', 16)->nullable();
            $table->string('to_stage', 16);
            $table->unsignedTinyInteger('probability');
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        // Calls, meetings, notes and follow-ups on a lead, client or opportunity.
        Schema::create('crm_activities', function (Blueprint $table) {
            $table->id();
            $table->morphs('subject');
            $table->string('type', 16);
            $table->string('title');
            $table->text('body')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('remind_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // responsible
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['remind_at', 'reminded_at']);
            $table->index(['user_id', 'completed_at', 'due_at']);
        });

        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('type');
                $table->morphs('notifiable');
                $table->text('data');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        foreach (['notifications', 'crm_activities', 'opportunity_stage_changes', 'opportunities', 'leads', 'contacts', 'clients'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
