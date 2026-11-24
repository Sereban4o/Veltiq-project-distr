<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('storage_uploads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('disk', 64);
            $table->text('target_path');
            $table->string('file_name', 255);
            $table->bigInteger('total_size');
            $table->unsignedInteger('chunk_size');
            $table->unsignedInteger('total_chunks');
            $table->string('checksum', 64)->nullable();
            $table->string('status', 16)->default('open');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('expires_at');
        });

        Schema::create('storage_upload_chunks', function (Blueprint $table) {
            $table->id();
            $table->uuid('upload_id');
            $table->unsignedInteger('chunk_index');
            $table->unsignedInteger('size');
            $table->string('checksum', 64)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['upload_id', 'chunk_index']);
            $table->foreign('upload_id')->references('id')->on('storage_uploads')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_upload_chunks');
        Schema::dropIfExists('storage_uploads');
    }
};
