<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('crm_deals', function (Blueprint $table) {
            // Date the deal reached a terminal status (won/lost), used by revenue reports.
            $table->timestamp('closed_at')->nullable()->after('status');
            $table->index(['status', 'closed_at']);
        });

        // Backfill terminal deals so reports are populated from day one.
        DB::table('crm_deals')
            ->whereIn('status', ['won', 'lost'])
            ->whereNull('closed_at')
            ->update(['closed_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('crm_deals', function (Blueprint $table) {
            $table->dropIndex(['status', 'closed_at']);
            $table->dropColumn('closed_at');
        });
    }
};
