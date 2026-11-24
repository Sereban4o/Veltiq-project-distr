<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // null = inherit the global default, -1 = unlimited.
            $table->bigInteger('storage_quota_bytes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('storage_quota_bytes');
        });
    }
};
