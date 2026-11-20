<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('crm_pipeline_stages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('color', 20)->default('#3390ec');
            $table->boolean('is_won')->default(false);
            $table->boolean('is_lost')->default(false);
            $table->timestamps();
        });

        Schema::create('crm_companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('website')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('industry')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('crm_contacts', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('position')->nullable();
            $table->foreignId('company_id')->nullable()->constrained('crm_companies')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('crm_deals', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->foreignId('company_id')->nullable()->constrained('crm_companies')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('crm_contacts')->nullOnDelete();
            $table->foreignId('stage_id')->nullable()->constrained('crm_pipeline_stages')->nullOnDelete();
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('currency', 8)->default('RUB');
            $table->unsignedTinyInteger('probability')->default(0);
            $table->date('expected_close_date')->nullable();
            $table->string('status', 16)->default('open'); // open | won | lost
            $table->text('description')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['stage_id', 'status']);
            $table->index('owner_id');
        });

        Schema::create('crm_activities', function (Blueprint $table) {
            $table->id();
            $table->string('type', 16)->default('task'); // call | meeting | task | email | note
            $table->string('subject');
            $table->text('description')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->boolean('is_completed')->default(false);
            $table->foreignId('deal_id')->nullable()->constrained('crm_deals')->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('crm_contacts')->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('crm_companies')->cascadeOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_completed', 'due_at']);
        });

        // Default sales pipeline so the board is usable immediately.
        $now = now();
        $stages = [
            ['name' => 'Новая', 'sort_order' => 10, 'color' => '#6B778C'],
            ['name' => 'В работе', 'sort_order' => 20, 'color' => '#3390ec'],
            ['name' => 'Переговоры', 'sort_order' => 30, 'color' => '#FFAB00'],
            ['name' => 'Выиграно', 'sort_order' => 40, 'color' => '#36B37E', 'is_won' => true],
            ['name' => 'Проиграно', 'sort_order' => 50, 'color' => '#FF5630', 'is_lost' => true],
        ];

        foreach ($stages as $stage) {
            DB::table('crm_pipeline_stages')->insert([
                'name' => $stage['name'],
                'sort_order' => $stage['sort_order'],
                'color' => $stage['color'],
                'is_won' => $stage['is_won'] ?? false,
                'is_lost' => $stage['is_lost'] ?? false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_activities');
        Schema::dropIfExists('crm_deals');
        Schema::dropIfExists('crm_contacts');
        Schema::dropIfExists('crm_companies');
        Schema::dropIfExists('crm_pipeline_stages');
    }
};
