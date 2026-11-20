<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // Client-generated id used to make offline/queued sends idempotent:
            // retrying the same message must not create duplicates.
            $table->uuid('client_id')->nullable()->after('parent_id');
            $table->unique(['sender_id', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropUnique(['sender_id', 'client_id']);
            $table->dropColumn('client_id');
        });
    }
};
