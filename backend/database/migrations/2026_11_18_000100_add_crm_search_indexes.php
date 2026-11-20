<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Trigram (pg_trgm) GIN indexes for ILIKE '%...%' searches and composite
 * indexes for the soft-delete heavy CRM listings.
 */
return new class() extends Migration {
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
            DB::statement('CREATE INDEX IF NOT EXISTS crm_companies_name_trgm_idx ON crm_companies USING gin (name gin_trgm_ops)');
            DB::statement('CREATE INDEX IF NOT EXISTS crm_companies_inn_trgm_idx ON crm_companies USING gin (inn gin_trgm_ops)');
            DB::statement('CREATE INDEX IF NOT EXISTS issues_summary_trgm_idx ON issues USING gin (summary gin_trgm_ops)');
        }

        Schema::table('crm_companies', function (Blueprint $table) {
            $table->index(['deleted_at', 'name'], 'crm_companies_deleted_name_idx');
            $table->index(['owner_id', 'deleted_at'], 'crm_companies_owner_deleted_idx');
        });

        Schema::table('crm_contacts', function (Blueprint $table) {
            $table->index(['owner_id', 'deleted_at'], 'crm_contacts_owner_deleted_idx');
        });

        Schema::table('crm_deals', function (Blueprint $table) {
            $table->index(['owner_id', 'deleted_at'], 'crm_deals_owner_deleted_idx');
        });

        Schema::table('crm_activities', function (Blueprint $table) {
            $table->index(['owner_id', 'deleted_at'], 'crm_activities_owner_deleted_idx');
        });
    }

    public function down(): void
    {
        Schema::table('crm_companies', function (Blueprint $table) {
            $table->dropIndex('crm_companies_deleted_name_idx');
            $table->dropIndex('crm_companies_owner_deleted_idx');
        });

        Schema::table('crm_contacts', function (Blueprint $table) {
            $table->dropIndex('crm_contacts_owner_deleted_idx');
        });

        Schema::table('crm_deals', function (Blueprint $table) {
            $table->dropIndex('crm_deals_owner_deleted_idx');
        });

        Schema::table('crm_activities', function (Blueprint $table) {
            $table->dropIndex('crm_activities_owner_deleted_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS crm_companies_name_trgm_idx');
            DB::statement('DROP INDEX IF EXISTS crm_companies_inn_trgm_idx');
            DB::statement('DROP INDEX IF EXISTS issues_summary_trgm_idx');
        }
    }
};
