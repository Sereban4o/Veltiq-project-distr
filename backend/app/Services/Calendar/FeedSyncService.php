<?php

namespace App\Services\Calendar;

use App\Models\CalendarFeed;
use App\Models\CalendarFeedEvent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class FeedSyncService
{
    public function __construct(
        private SafeUrlFetcher $fetcher,
        private IcsService $ics,
    ) {
    }

    /**
     * @return array{added: int, updated: int, removed: int, status: string}
     */
    public function sync(CalendarFeed $feed): array
    {
        $lock = Cache::lock("calendar-feed:{$feed->id}", 300);

        if (! $lock->get()) {
            return ['added' => 0, 'updated' => 0, 'removed' => 0, 'status' => 'locked'];
        }

        try {
            $result = $this->fetcher->fetch($feed->url, $feed->etag, $feed->last_modified);

            if ($result['status'] === 304) {
                $feed->forceFill(['last_synced_at' => now(), 'last_error' => null])->save();

                return ['added' => 0, 'updated' => 0, 'removed' => 0, 'status' => 'not_modified'];
            }

            $rows = $this->ics->parseFeed($result['body']);

            return DB::transaction(function () use ($feed, $rows, $result) {
                $seen = [];
                $added = 0;
                $updated = 0;

                foreach ($rows as $row) {
                    $key = $row['external_uid'] . '|' . $row['recurrence_id'];
                    $seen[$key] = true;

                    $payload = [
                        'summary' => $row['title'],
                        'description' => $row['description'],
                        'location' => $row['location'],
                        'starts_at' => $row['starts_at'],
                        'ends_at' => $row['ends_at'],
                        'all_day' => $row['all_day'],
                        'timezone' => $row['timezone'],
                        'rrule' => $row['rrule'],
                        'exdates' => $row['exdates'],
                        'is_cancelled' => $row['is_cancelled'],
                        'sequence' => $row['sequence'],
                        'raw' => $row['raw'],
                    ];

                    $existing = CalendarFeedEvent::query()
                        ->where('feed_id', $feed->id)
                        ->where('external_uid', $row['external_uid'])
                        ->where('recurrence_id', $row['recurrence_id'])
                        ->first();

                    if ($existing !== null) {
                        $existing->update($payload);
                        $updated++;
                    } else {
                        $feed->feedEvents()->create($payload + [
                            'external_uid' => $row['external_uid'],
                            'recurrence_id' => $row['recurrence_id'],
                        ]);
                        $added++;
                    }
                }

                $removed = 0;
                foreach ($feed->feedEvents()->get() as $stored) {
                    if (! isset($seen[$stored->external_uid . '|' . $stored->recurrence_id])) {
                        $stored->delete();
                        $removed++;
                    }
                }

                $feed->forceFill([
                    'etag' => $result['etag'],
                    'last_modified' => $result['last_modified'],
                    'last_synced_at' => now(),
                    'last_error' => null,
                ])->save();

                return ['added' => $added, 'updated' => $updated, 'removed' => $removed, 'status' => 'ok'];
            });
        } catch (Throwable $e) {
            $feed->forceFill(['last_error' => Str::limit($e->getMessage(), 1000)])->save();

            throw $e;
        } finally {
            $lock->release();
        }
    }
}
