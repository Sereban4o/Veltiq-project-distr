<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('issue_attachments', function (Blueprint $table) {
            // null = attachment on the issue itself; set = attachment on a comment.
            $table->foreignId('comment_id')->nullable()
                ->after('issue_id')
                ->constrained('issue_comments')->nullOnDelete();

            $table->index('comment_id');
        });
    }

    public function down(): void
    {
        Schema::table('issue_attachments', function (Blueprint $table) {
            $table->dropIndex(['comment_id']);
            $table->dropConstrainedForeignId('comment_id');
        });
    }
};
