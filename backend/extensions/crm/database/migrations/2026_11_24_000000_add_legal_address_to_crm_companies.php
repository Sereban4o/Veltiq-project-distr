<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('crm_companies', function (Blueprint $table) {
            // 1С отдаёт юридический адрес отдельной строкой ContactInfo
            // (Kind = «Юридический адрес»); храним его в собственной колонке,
            // а «Фактический адрес» остаётся в legacy-поле `address`.
            $table->string('legal_address', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('crm_companies', function (Blueprint $table) {
            $table->dropColumn('legal_address');
        });
    }
};
