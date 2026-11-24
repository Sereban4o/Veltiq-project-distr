<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // Postgres does not index foreign keys automatically; threads are
            // listed by parent_id and ordered by creation time.
            $table->index('parent_id');
            $table->index(['parent_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['parent_id', 'created_at']);
            $table->dropIndex(['parent_id']);
        });
    }
};
