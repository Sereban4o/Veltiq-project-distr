<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('storage_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64);
            $table->string('disk', 64)->nullable();
            $table->text('path')->nullable();
            $table->string('path_hash', 64)->nullable();
            $table->unsignedBigInteger('entry_id')->nullable();
            $table->bigInteger('size')->nullable();
            $table->unsignedSmallInteger('status')->default(200);
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->uuid('request_id')->nullable();
            $table->jsonb('payload')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('created_at');
            $table->index(['action', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index('path_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_audit_logs');
    }
};
