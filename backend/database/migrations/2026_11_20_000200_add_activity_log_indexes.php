<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            // Feed lookups are always scoped to a single subject, newest first.
            $table->index(['subject_type', 'subject_id', 'created_at'], 'activity_logs_subject_created_index');
            $table->index(['user_id', 'created_at'], 'activity_logs_user_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex('activity_logs_subject_created_index');
            $table->dropIndex('activity_logs_user_created_index');
        });
    }
};
