<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('crm_activities', function (Blueprint $table) {
            $table->string('direction', 8)->nullable();        // inbound | outbound
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('phone', 64)->nullable();
            $table->string('outcome', 32)->nullable();          // connected | no_answer | busy | callback
        });
    }

    public function down(): void
    {
        Schema::table('crm_activities', function (Blueprint $table) {
            $table->dropColumn(['direction', 'duration_seconds', 'phone', 'outcome']);
        });
    }
};
