<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('storage_shares', function (Blueprint $table) {
            $table->id();
            $table->string('token_hash', 64)->unique();
            $table->foreignId('entry_id')->constrained('storage_meta')->cascadeOnDelete();
            $table->string('disk', 64);
            $table->text('path');
            $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('password_hash')->nullable();
            $table->boolean('can_download')->default(true);
            $table->unsignedInteger('max_downloads')->nullable();
            $table->unsignedInteger('downloads_count')->default(0);
            $table->unsignedSmallInteger('failed_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_accessed_at')->nullable();
            $table->timestamps();

            $table->index('entry_id');
            $table->index('created_by');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_shares');
    }
};
