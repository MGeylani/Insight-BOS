<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A file attached to any record. The file lives in the external storage provider; BOS keeps metadata.
        // While an upload is pending or failed, the bytes wait in temp_path (local disk) for a retry,
        // and are removed as soon as the upload succeeds.
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->morphs('attachable'); // client | project | contract | quote ...
            $table->foreignId('storage_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 32)->nullable();
            $table->string('external_id', 1024)->nullable(); // provider file id or path
            $table->string('name');
            $table->unsignedBigInteger('size')->default(0);
            $table->string('mime', 190)->nullable();
            $table->string('url', 2048)->nullable(); // opens the file in the provider's web app
            $table->string('status', 16)->default('pending'); // pending | uploaded | failed
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('temp_path')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'attempts']);
        });

        // Provider folder ids, so each client/project folder is found or created once per connection.
        Schema::create('storage_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('storage_connection_id')->constrained()->cascadeOnDelete();
            $table->string('key', 190); // root | client:5 | client:5:contracts | project:3 ...
            $table->string('external_id', 1024);
            $table->timestamps();
            $table->unique(['storage_connection_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_folders');
        Schema::dropIfExists('attachments');
    }
};
