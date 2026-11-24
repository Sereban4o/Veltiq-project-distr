<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('crm_companies', function (Blueprint $table) {
            // 1С internal identifier (Ссылка.УникальныйИдентификатор()).
            $table->uuid('ref_1c')->nullable()->unique();
            $table->string('code_1c', 32)->nullable();
            $table->string('full_name', 500)->nullable();
            $table->string('inn', 12)->nullable();
            $table->string('kpp', 12)->nullable();
            $table->string('legal_physical_person', 32)->nullable();
            $table->string('registration_country', 128)->nullable();
            $table->string('registration_number', 64)->nullable();
            $table->string('tax_number', 64)->nullable();
            $table->string('okpo_code', 32)->nullable();
            $table->string('head_counterpart', 255)->nullable();
            $table->string('partner_1c', 255)->nullable();
            $table->string('international_description', 500)->nullable();
            $table->string('transcription_description', 500)->nullable();
            $table->text('additional_information')->nullable();
            $table->jsonb('contact_info')->nullable();
            $table->boolean('deleted_in_1c')->default(false);
            $table->timestamp('synced_at')->nullable();

            $table->index('inn');
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::table('crm_companies', function (Blueprint $table) {
            $table->dropUnique(['ref_1c']);
            $table->dropIndex(['inn']);
            $table->dropIndex(['name']);
            $table->dropColumn([
                'ref_1c', 'code_1c', 'full_name', 'inn', 'kpp', 'legal_physical_person',
                'registration_country', 'registration_number', 'tax_number', 'okpo_code',
                'head_counterpart', 'partner_1c', 'international_description',
                'transcription_description', 'additional_information', 'contact_info',
                'deleted_in_1c', 'synced_at',
            ]);
        });
    }
};
