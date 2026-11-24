<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('mail_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });

        Schema::create('mail_filter_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->foreignId('action_target_folder_id')
                ->constrained('mail_folders')
                ->cascadeOnDelete();
            $table->json('conditions');
            $table->timestamps();

            $table->index(['user_id', 'is_active', 'position']);
        });

        Schema::table('mail_messages', function (Blueprint $table) {
            // null = system folder (the `folder` column); set = custom folder.
            $table->foreignId('mail_folder_id')->nullable()
                ->constrained('mail_folders')->nullOnDelete();

            $table->index(['user_id', 'mail_folder_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::table('mail_messages', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'mail_folder_id', 'received_at']);
            $table->dropConstrainedForeignId('mail_folder_id');
        });

        Schema::dropIfExists('mail_filter_rules');
        Schema::dropIfExists('mail_folders');
    }
};
