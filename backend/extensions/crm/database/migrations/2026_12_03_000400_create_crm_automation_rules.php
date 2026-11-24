<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('crm_automation_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('trigger', 32);            // deal_created | stage_changed | deal_won
            $table->jsonb('conditions')->nullable();
            $table->string('action', 32);             // create_task | send_notification
            $table->jsonb('action_payload')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_automation_rules');
    }
};
