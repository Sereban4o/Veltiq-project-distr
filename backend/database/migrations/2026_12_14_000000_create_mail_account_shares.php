<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        // Members of a shared mailbox. The account owner always has access;
        // each row grants another user access to the account's messages.
        Schema::create('mail_account_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_account_id')->constrained('mail_accounts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('can_send')->default(false);
            $table->timestamps();

            $table->unique(['mail_account_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_account_shares');
    }
};
