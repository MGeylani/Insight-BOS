<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Permission templates: optional presets that can be applied to a user, then customised.
        Schema::create('role_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('description')->nullable();
            $table->json('permissions'); // {"crm.view": "all", "tasks.edit": "own", ...}
            $table->timestamps();
        });

        // Direct per-user grants. The job title never grants access; only rows here do.
        Schema::create('user_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('permission', 64);
            $table->string('scope', 16)->default('all'); // own | assigned | all
            $table->timestamps();
            $table->unique(['user_id', 'permission']);
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        Schema::create('currencies', function (Blueprint $table) {
            $table->string('code', 3)->primary();
            $table->string('name');
            $table->string('symbol', 8);
            $table->unsignedTinyInteger('decimals')->default(2);
            $table->boolean('is_base')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Daily rates entered manually: 1 unit of base currency (USD) = rate units of currency_code.
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->string('currency_code', 3);
            $table->date('date');
            $table->decimal('rate', 18, 6);
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['currency_code', 'date']);
            $table->foreign('currency_code')->references('code')->on('currencies')->cascadeOnDelete();
        });

        Schema::create('service_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('tax_rate', 5, 2)->default(0); // percent, entered manually
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('number_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('type', 32)->unique(); // invoice, quote, receipt, expense
            $table->string('label');
            $table->string('prefix', 16);
            $table->unsignedTinyInteger('padding')->default(4);
            $table->boolean('yearly_reset')->default(true);
            $table->unsignedSmallInteger('current_year')->nullable();
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
        });

        // One active external storage provider for the whole system.
        Schema::create('storage_connections', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32); // google_drive | onedrive | dropbox | wd_mycloud
            $table->string('status', 16)->default('disconnected'); // connected | disconnected | error
            $table->string('account_label')->nullable();
            $table->string('root_folder_id')->nullable();
            $table->string('root_folder_name')->nullable();
            $table->text('credentials')->nullable(); // encrypted cast
            $table->unsignedInteger('max_upload_mb')->default(100);
            $table->json('allowed_types')->nullable();
            $table->boolean('is_active')->default(false);
            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 64);
            $table->nullableMorphs('subject');
            $table->string('description')->nullable();
            $table->json('changes')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['activity_log', 'storage_connections', 'number_sequences', 'service_categories',
            'exchange_rates', 'currencies', 'settings', 'user_permissions', 'role_templates'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
