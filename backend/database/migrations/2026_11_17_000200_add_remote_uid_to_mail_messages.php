<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('mail_messages', function (Blueprint $table) {
            // Provider-side identifier used to delete the message on the server
            // (IMAP UID). `uid` keeps the RFC Message-ID for de-duplication.
            $table->string('remote_uid')->nullable()->after('uid');
            $table->index(['mail_account_id', 'remote_uid']);
        });
    }

    public function down(): void
    {
        Schema::table('mail_messages', function (Blueprint $table) {
            $table->dropIndex(['mail_account_id', 'remote_uid']);
            $table->dropColumn('remote_uid');
        });
    }
};
