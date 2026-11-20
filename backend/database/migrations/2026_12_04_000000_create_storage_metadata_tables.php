<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Metadata overlay for the file browser. The storage driver stays the source
 * of truth for "what exists"; these tables only extend entries with an id,
 * ownership, version counters, preview state and trash bookkeeping.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('storage_meta', function (Blueprint $table) {
            $table->id();
            $table->string('disk', 64);
            $table->text('path');
            $table->string('path_hash', 64);
            $table->text('parent_path')->default('');
            $table->string('type', 8)->default('file');
            $table->bigInteger('size')->default(0);
            $table->string('mime', 191)->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(0);
            $table->string('preview_status', 16)->nullable();
            $table->timestamp('preview_at')->nullable();
            $table->string('last_office_key', 64)->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            $table->index(['disk', 'parent_path']);
            $table->index(['disk', 'owner_id']);
            $table->index('parent_path');
            $table->index('deleted_at');
        });

        // Trashed entries keep their row (with deleted_at set), so the unique
        // index must only cover live paths.
        DB::statement(
            'CREATE UNIQUE INDEX storage_meta_disk_path_unique ON storage_meta (disk, path_hash) WHERE deleted_at IS NULL',
        );

        Schema::create('storage_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entry_id')->constrained('storage_meta')->cascadeOnDelete();
            $table->string('disk', 64);
            $table->text('path');
            $table->unsignedInteger('version');
            $table->bigInteger('size')->default(0);
            $table->string('mime', 191)->nullable();
            $table->string('checksum', 64)->nullable();
            $table->string('source', 16)->default('upload');
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('office_key', 64)->nullable();
            $table->string('status', 16)->default('ready');
            $table->string('error', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['entry_id', 'version']);
            $table->index(['entry_id', 'created_at']);
        });

        Schema::create('storage_trash', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entry_id')->constrained('storage_meta')->cascadeOnDelete();
            $table->string('disk', 64);
            $table->text('original_path');
            $table->string('original_path_hash', 64);
            $table->text('parent_path')->default('');
            $table->string('type', 8)->default('file');
            $table->bigInteger('size')->default(0);
            $table->unsignedInteger('items_count')->default(0);
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('trash_key', 64);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('purged_at')->nullable();
            $table->timestamps();

            $table->index(['owner_id', 'created_at']);
            $table->index('expires_at');
            $table->index('entry_id');
            $table->index('original_path_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_trash');
        Schema::dropIfExists('storage_versions');
        DB::statement('DROP INDEX IF EXISTS storage_meta_disk_path_unique');
        Schema::dropIfExists('storage_meta');
    }
};
