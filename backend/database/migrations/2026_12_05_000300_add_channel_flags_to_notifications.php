<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->boolean('in_app')->default(true)->after('read');
            $table->index(['user_id', 'in_app', 'read']);
        });

        // Notifications may come from external sources (e.g. 1С) without a
        // local subject, so the polymorphic subject becomes optional.
        Schema::table('notifications', function (Blueprint $table) {
            $table->string('subject_type')->nullable()->change();
            $table->unsignedBigInteger('subject_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'in_app', 'read']);
            $table->dropColumn('in_app');
        });
    }
};
