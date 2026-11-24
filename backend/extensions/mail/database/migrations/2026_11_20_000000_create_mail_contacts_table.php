<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('mail_contacts', function (Blueprint $table) {
            $table->id();
            $table->string('email', 255)->unique();
            $table->string('name', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
            DB::statement('CREATE INDEX IF NOT EXISTS mail_contacts_email_trgm_idx ON mail_contacts USING gin (email gin_trgm_ops)');
            DB::statement('CREATE INDEX IF NOT EXISTS mail_contacts_name_trgm_idx ON mail_contacts USING gin (name gin_trgm_ops)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS mail_contacts_email_trgm_idx');
            DB::statement('DROP INDEX IF EXISTS mail_contacts_name_trgm_idx');
        }

        Schema::dropIfExists('mail_contacts');
    }
};
