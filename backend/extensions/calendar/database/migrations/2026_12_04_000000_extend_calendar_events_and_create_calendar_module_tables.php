<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        // ─── Extend calendar_events ───
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('all_day')->default(false);
            $table->string('timezone', 64)->default('UTC');
            $table->text('rrule')->nullable();
            $table->json('exdates')->nullable();
            $table->timestamp('recurrence_until')->nullable();
            $table->string('location', 255)->nullable();
            $table->foreignId('organizer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('visibility', 16)->default('private');
            $table->string('source', 16)->default('local');
            $table->string('external_uid', 255)->nullable();
            $table->unsignedInteger('external_sequence')->nullable();
        });

        // Backfill the new timeline fields from the legacy date column.
        DB::statement(
            'UPDATE calendar_events
                SET starts_at = event_date,
                    organizer_id = user_id,
                    all_day = true,
                    timezone = ?
              WHERE starts_at IS NULL',
            [config('app.timezone', 'UTC')],
        );

        // On MySQL an index backing a foreign key cannot be dropped while it is
        // the only one, so add the replacement indexes first.
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->index(['user_id', 'starts_at']);
            $table->index(['organizer_id', 'starts_at']);
            $table->index(['starts_at', 'ends_at']);
        });

        Schema::table('calendar_events', function (Blueprint $table) {
            $table->timestamp('starts_at')->nullable(false)->change();
            $table->dropIndex(['user_id', 'event_date']);
            $table->dropColumn('event_date');
            $table->unique(['user_id', 'source', 'external_uid']);
        });

        // ─── Attendees / RSVP ───
        Schema::create('calendar_event_attendees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('calendar_events')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('email', 255)->nullable();
            $table->string('name', 255)->nullable();
            $table->string('status', 16)->default('needs_action');
            $table->string('role', 16)->default('required');
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'user_id']);
            $table->index(['user_id', 'status']);
            $table->index(['event_id', 'status']);
        });

        // ─── Links to issues / deals ───
        Schema::create('calendar_event_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('calendar_events')->cascadeOnDelete();
            $table->foreignId('issue_id')->nullable()->constrained('issues')->nullOnDelete();
            $table->foreignId('deal_id')->nullable()->constrained('crm_deals')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['event_id', 'issue_id']);
            $table->unique(['event_id', 'deal_id']);
            $table->index('issue_id');
            $table->index('deal_id');
        });

        // MySQL forbids CHECK constraints on columns involved in a foreign key
        // with a referential action, so the "exactly one target" rule is enforced
        // by the application there.
        if (in_array(DB::connection()->getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement(
                'ALTER TABLE calendar_event_links
                    ADD CONSTRAINT calendar_event_links_one_target
                    CHECK ((issue_id IS NOT NULL) <> (deal_id IS NOT NULL))',
            );
        }

        // ─── External feed subscriptions ───
        Schema::create('calendar_feeds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('url');
            $table->string('color', 20)->nullable();
            $table->unsignedSmallInteger('sync_interval_minutes')->default(60);
            $table->boolean('is_active')->default(true);
            $table->string('etag', 255)->nullable();
            $table->string('last_modified', 255)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'is_active']);
            $table->index(['is_active', 'last_synced_at']);
        });

        // ─── Read-only mirror of external feed events ───
        Schema::create('calendar_feed_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feed_id')->constrained('calendar_feeds')->cascadeOnDelete();
            $table->string('external_uid', 255);
            $table->string('recurrence_id', 64)->default('');
            $table->string('summary');
            $table->text('description')->nullable();
            $table->string('location', 255)->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->boolean('all_day')->default(false);
            $table->string('timezone', 64)->default('UTC');
            $table->text('rrule')->nullable();
            $table->json('exdates')->nullable();
            $table->boolean('is_cancelled')->default(false);
            $table->unsignedInteger('sequence')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->unique(['feed_id', 'external_uid', 'recurrence_id']);
            $table->index(['feed_id', 'starts_at']);
            $table->index(['starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_feed_events');
        Schema::dropIfExists('calendar_feeds');
        Schema::dropIfExists('calendar_event_links');
        Schema::dropIfExists('calendar_event_attendees');

        Schema::table('calendar_events', function (Blueprint $table) {
            $table->date('event_date')->nullable();
        });

        DB::statement('UPDATE calendar_events SET event_date = CAST(starts_at AS DATE) WHERE event_date IS NULL');

        Schema::table('calendar_events', function (Blueprint $table) {
            $table->date('event_date')->nullable(false)->change();
            $table->dropUnique(['user_id', 'source', 'external_uid']);
            $table->dropIndex(['user_id', 'starts_at']);
            $table->dropIndex(['organizer_id', 'starts_at']);
            $table->dropIndex(['starts_at', 'ends_at']);
            $table->dropConstrainedForeignId('organizer_id');
            $table->dropColumn([
                'starts_at',
                'ends_at',
                'all_day',
                'timezone',
                'rrule',
                'exdates',
                'recurrence_until',
                'location',
                'visibility',
                'source',
                'external_uid',
                'external_sequence',
            ]);
            $table->index(['user_id', 'event_date']);
        });
    }
};
