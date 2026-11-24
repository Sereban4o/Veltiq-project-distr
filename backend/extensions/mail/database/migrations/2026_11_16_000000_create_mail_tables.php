<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        // Per-user mailbox connection settings (one mailbox per user).
        Schema::create('mail_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('email');
            $table->string('display_name')->nullable();

            // Incoming protocol: imap or pop
            $table->string('incoming_protocol', 8)->default('imap');

            $table->string('imap_host')->nullable();
            $table->unsignedInteger('imap_port')->nullable();
            $table->string('imap_encryption', 8)->nullable(); // ssl | tls | none
            $table->string('imap_username')->nullable();
            $table->text('imap_password')->nullable();

            $table->string('pop_host')->nullable();
            $table->unsignedInteger('pop_port')->nullable();
            $table->string('pop_encryption', 8)->nullable();
            $table->string('pop_username')->nullable();
            $table->text('pop_password')->nullable();

            $table->string('smtp_host')->nullable();
            $table->unsignedInteger('smtp_port')->nullable();
            $table->string('smtp_encryption', 8)->nullable();
            $table->string('smtp_username')->nullable();
            $table->text('smtp_password')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_sync_error')->nullable();

            $table->timestamps();
        });

        Schema::create('mail_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_account_id')->nullable()->constrained('mail_accounts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // inbox | sent | drafts | trash
            $table->string('folder', 16)->default('inbox');

            // Remote identifiers (for de-duplication on sync).
            $table->string('uid')->nullable();
            $table->string('message_id')->nullable();
            $table->string('in_reply_to')->nullable();

            $table->string('from_email')->nullable();
            $table->string('from_name')->nullable();
            $table->json('to_recipients')->nullable();
            $table->json('cc_recipients')->nullable();
            $table->json('bcc_recipients')->nullable();

            $table->string('subject')->nullable();
            $table->string('preview')->nullable();
            $table->longText('body_text')->nullable();
            $table->longText('body_html')->nullable();

            $table->boolean('is_read')->default(false);
            $table->boolean('is_starred')->default(false);
            $table->boolean('has_attachments')->default(false);

            // local | imap | pop | smtp
            $table->string('source', 8)->default('local');

            $table->longText('raw_headers')->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'folder', 'received_at']);
            $table->index(['mail_account_id', 'folder', 'received_at']);
            $table->unique(['mail_account_id', 'folder', 'uid']);
        });

        Schema::create('mail_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_message_id')->constrained('mail_messages')->cascadeOnDelete();
            $table->string('filename');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('path')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_attachments');
        Schema::dropIfExists('mail_messages');
        Schema::dropIfExists('mail_accounts');
    }
};
