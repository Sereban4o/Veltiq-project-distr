<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('boards', function (Blueprint $table) {
            // Switchable swimlane grouping, stored per board (shared by the team).
            $table->string('swimlane_by', 32)->default('none');
            $table->boolean('wip_enforcement')->default(false);
        });

        if (! Schema::hasColumn('saved_filters', 'filters')) {
            Schema::table('saved_filters', function (Blueprint $table) {
                $table->json('filters')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('saved_filters', 'filters')) {
            Schema::table('saved_filters', function (Blueprint $table) {
                $table->dropColumn('filters');
            });
        }

        Schema::table('boards', function (Blueprint $table) {
            $table->dropColumn(['swimlane_by', 'wip_enforcement']);
        });
    }
};
