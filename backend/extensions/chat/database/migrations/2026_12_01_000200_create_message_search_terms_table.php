<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        // Blind index for message search: the message body is encrypted with a
        // random IV, so SQL LIKE/full-text cannot be used. Each message stores
        // the HMAC of every normalised word instead.
        Schema::create('message_search_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chat_id')->constrained('chats')->cascadeOnDelete();
            $table->char('term', 64);
            $table->unique(['message_id', 'term']);
            $table->index(['chat_id', 'term']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_search_terms');
    }
};
