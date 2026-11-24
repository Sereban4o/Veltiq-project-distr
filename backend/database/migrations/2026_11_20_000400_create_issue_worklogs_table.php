<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('issue_worklogs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('issue_id')->constrained('issues')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('seconds')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->boolean('is_running')->default(false);
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->index(['issue_id', 'started_at']);
        });

        // A user can have only one running timer per issue. Partial unique
        // indexes exist in PostgreSQL/SQLite only; MySQL/MariaDB cannot express
        // them, so the rule is enforced by the application there.
        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement(
                'CREATE UNIQUE INDEX IF NOT EXISTS issue_worklogs_running_unique
                 ON issue_worklogs (issue_id, user_id)
                 WHERE is_running',
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_worklogs');
    }
};
