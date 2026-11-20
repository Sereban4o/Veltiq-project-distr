<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('crm_deal_stage_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained('crm_deals')->cascadeOnDelete();
            $table->foreignId('from_stage_id')->nullable()->constrained('crm_pipeline_stages')->nullOnDelete();
            $table->foreignId('to_stage_id')->nullable()->constrained('crm_pipeline_stages')->nullOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['deal_id', 'created_at']);
            $table->index(['from_stage_id', 'to_stage_id']);
        });

        // Seed a single entry per existing deal so stage conversion has a starting point.
        DB::table('crm_deals')
            ->whereNotNull('stage_id')
            ->orderBy('id')
            ->chunk(500, function ($deals) {
                $rows = [];
                foreach ($deals as $deal) {
                    $rows[] = [
                        'deal_id' => $deal->id,
                        'from_stage_id' => null,
                        'to_stage_id' => $deal->stage_id,
                        'changed_by' => $deal->owner_id,
                        'created_at' => $deal->created_at,
                        'updated_at' => $deal->created_at,
                    ];
                }
                if ($rows !== []) {
                    DB::table('crm_deal_stage_history')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_deal_stage_history');
    }
};
