<?php

use App\Services\Mail\MailThreadResolver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('mail_messages', function (Blueprint $table) {
            $table->string('thread_key', 64)->nullable()->after('in_reply_to');
            $table->text('references')->nullable()->after('in_reply_to');

            $table->index(['user_id', 'thread_key'], 'mail_messages_user_thread_idx');
            $table->index(['user_id', 'thread_key', 'received_at'], 'mail_messages_user_thread_date_idx');
        });

        $this->backfill();
    }

    /**
     * Assign a thread key to every existing message.
     *
     * Rows are processed chronologically so a reply normally sees its parent
     * already keyed. A reply that arrives before its parent (out of order)
     * starts its own thread — acceptable because sync stores in arrival order.
     */
    private function backfill(): void
    {
        $keyByMessageId = [];

        DB::table('mail_messages')
            ->select('id', 'message_id', 'in_reply_to', 'subject')
            ->orderByRaw('coalesce(received_at, sent_at, created_at) asc')
            ->orderBy('id')
            ->chunk(500, function ($rows) use (&$keyByMessageId): void {
                foreach ($rows as $row) {
                    $parentKey = null;

                    if ($row->in_reply_to) {
                        $parentKey = $keyByMessageId[MailThreadResolver::normalizeId($row->in_reply_to)] ?? null;
                    }

                    $key = MailThreadResolver::computeKey($parentKey, $row->message_id, $row->subject);

                    if ($row->message_id) {
                        $keyByMessageId[MailThreadResolver::normalizeId($row->message_id)] = $key;
                    }

                    DB::table('mail_messages')->where('id', $row->id)->update(['thread_key' => $key]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('mail_messages', function (Blueprint $table) {
            $table->dropIndex('mail_messages_user_thread_idx');
            $table->dropIndex('mail_messages_user_thread_date_idx');
            $table->dropColumn(['thread_key', 'references']);
        });
    }
};
