<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
            DB::statement('CREATE INDEX IF NOT EXISTS mail_messages_subject_trgm_idx ON mail_messages USING gin (subject gin_trgm_ops)');
            DB::statement('CREATE INDEX IF NOT EXISTS mail_messages_from_email_trgm_idx ON mail_messages USING gin (from_email gin_trgm_ops)');
            DB::statement('CREATE INDEX IF NOT EXISTS mail_messages_from_name_trgm_idx ON mail_messages USING gin (from_name gin_trgm_ops)');
        }

        // Body/preview are intentionally not trigram-indexed: `body_text` is a
        // longText column and a GIN index there would bloat the table. The `q`
        // search over bodies is a scan bounded by the user/folder index below.
        Schema::table('mail_messages', function (Blueprint $table) {
            $table->index(['user_id', 'from_email'], 'mail_messages_user_from_idx');
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS mail_messages_subject_trgm_idx');
            DB::statement('DROP INDEX IF EXISTS mail_messages_from_email_trgm_idx');
            DB::statement('DROP INDEX IF EXISTS mail_messages_from_name_trgm_idx');
        }

        Schema::table('mail_messages', function (Blueprint $table) {
            $table->dropIndex('mail_messages_user_from_idx');
        });
    }
};
