<?php

namespace App\Observers;

use App\Models\Crm\CrmDealStageHistory;
use App\Models\Crm\Deal;
use Illuminate\Support\Facades\Auth;

/**
 * Records pipeline stage transitions and keeps `closed_at` in sync with the
 * terminal `won`/`lost` statuses. Centralising this here means stage changes
 * coming from the API, CSV import or any future code path are captured alike.
 */
class DealObserver
{
    public function created(Deal $deal): void
    {
        if ($deal->stage_id) {
            CrmDealStageHistory::create([
                'deal_id' => $deal->id,
                'from_stage_id' => null,
                'to_stage_id' => $deal->stage_id,
                'changed_by' => Auth::id(),
            ]);
        }

        $this->syncClosedAt($deal);
    }

    public function updated(Deal $deal): void
    {
        if ($deal->wasChanged('stage_id')) {
            CrmDealStageHistory::create([
                'deal_id' => $deal->id,
                'from_stage_id' => $deal->getOriginal('stage_id'),
                'to_stage_id' => $deal->stage_id,
                'changed_by' => Auth::id(),
            ]);
        }

        $this->syncClosedAt($deal);
    }

    private function syncClosedAt(Deal $deal): void
    {
        if (! $deal->wasRecentlyCreated && ! $deal->wasChanged('status')) {
            return;
        }

        if (in_array($deal->status, [Deal::STATUS_WON, Deal::STATUS_LOST], true) && $deal->closed_at === null) {
            $deal->forceFill(['closed_at' => now()])->saveQuietly();
        } elseif ($deal->status === Deal::STATUS_OPEN && $deal->closed_at !== null) {
            $deal->forceFill(['closed_at' => null])->saveQuietly();
        }
    }
}
