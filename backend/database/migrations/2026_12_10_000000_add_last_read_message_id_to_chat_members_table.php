<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('chat_members', function (Blueprint $table) {
            $table->unsignedBigInteger('last_read_message_id')->nullable()->after('last_read_at');
            $table->index(['chat_id', 'last_read_message_id']);
        });
    }

    public function down(): void
    {
        Schema::table('chat_members', function (Blueprint $table) {
            $table->dropIndex(['chat_id', 'last_read_message_id']);
            $table->dropColumn('last_read_message_id');
        });
    }
};
