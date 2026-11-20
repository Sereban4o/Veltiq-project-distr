<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->integer('position')->default(0)->after('parent_id');
            $table->index(['space_id', 'parent_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropIndex(['space_id', 'parent_id', 'position']);
            $table->dropColumn('position');
        });
    }
};
