<?php

namespace App\Extensions\Crm\Services;

use App\Extensions\Crm\Models\CrmDealStageHistory;
use App\Extensions\Crm\Models\Deal;
use App\Extensions\Crm\Models\PipelineStage;
use Illuminate\Support\Carbon;

/**
 * Aggregations backing the CRM "Отчёты" tab: sales funnel, stage-to-stage
 * conversion, revenue by period and an overall deal summary.
 *
 * The queries rely on PostgreSQL (date_trunc / EXTRACT); the project only
 * configures the pgsql driver.
 */
class CrmReportsService
{
    private const OPEN = Deal::STATUS_OPEN;

    /**
     * Open deals per stage with amounts and probability-weighted amounts.
     *
     * @return array<int, array<string, mixed>>
     */
    public function funnel(): array
    {
        $weighted = Deal::query()
            ->where('status', self::OPEN)
            ->whereNotNull('stage_id')
            ->selectRaw('stage_id, COALESCE(SUM(amount * probability / 100.0), 0) as weighted')
            ->groupBy('stage_id')
            ->pluck('weighted', 'stage_id');

        return PipelineStage::orderBy('sort_order')
            ->withCount(['deals as deals_count' => fn ($q) => $q->where('status', self::OPEN)])
            ->withSum(['deals as deals_sum' => fn ($q) => $q->where('status', self::OPEN)], 'amount')
            ->get()
            ->map(fn (PipelineStage $stage) => [
                'id' => $stage->id,
                'name' => $stage->name,
                'color' => $stage->color,
                'count' => (int) $stage->deals_count,
                'amount' => (float) ($stage->deals_sum ?? 0),
                'weighted_amount' => (float) ($weighted[$stage->id] ?? 0),
            ])
            ->all();
    }

    /**
     * Adjacent-stage conversion rates derived from the stage history table.
     *
     * @return array<string, mixed>
     */
    public function conversion(): array
    {
        $stages = PipelineStage::orderBy('sort_order')->get();

        $reached = CrmDealStageHistory::query()
            ->selectRaw('to_stage_id, COUNT(DISTINCT deal_id) as reached')
            ->whereNotNull('to_stage_id')
            ->groupBy('to_stage_id')
            ->pluck('reached', 'to_stage_id');

        $transitions = CrmDealStageHistory::query()
            ->selectRaw('from_stage_id, to_stage_id, COUNT(DISTINCT deal_id) as converted')
            ->whereNotNull('from_stage_id')
            ->whereNotNull('to_stage_id')
            ->groupBy('from_stage_id', 'to_stage_id')
            ->get()
            ->keyBy(fn ($row) => $row->from_stage_id . '-' . $row->to_stage_id);

        $pairs = [];
        foreach ($stages as $index => $stage) {
            $next = $stages[$index + 1] ?? null;
            if ($next === null) {
                continue;
            }

            $entered = (int) ($reached[$stage->id] ?? 0);
            $converted = (int) ($transitions[$stage->id . '-' . $next->id]->converted ?? 0);

            $pairs[] = [
                'from' => ['id' => $stage->id, 'name' => $stage->name],
                'to' => ['id' => $next->id, 'name' => $next->name],
                'entered' => $entered,
                'converted' => $converted,
                'rate' => $entered > 0 ? round($converted / $entered * 100, 1) : 0.0,
            ];
        }

        $won = Deal::where('status', Deal::STATUS_WON)->count();
        $lost = Deal::where('status', Deal::STATUS_LOST)->count();
        $closed = $won + $lost;

        return [
            'pairs' => $pairs,
            'won_rate' => $closed > 0 ? round($won / $closed * 100, 1) : 0.0,
            'lost_rate' => $closed > 0 ? round($lost / $closed * 100, 1) : 0.0,
        ];
    }

    /**
     * Won revenue grouped by month/quarter/year over the given window.
     *
     * @return array<string, mixed>
     */
    public function revenue(string $period, ?string $from, ?string $to): array
    {
        $period = in_array($period, ['month', 'quarter', 'year'], true) ? $period : 'month';

        $toDate = $to ? Carbon::parse($to)->endOfDay() : Carbon::now()->endOfDay();
        $fromDate = $from ? Carbon::parse($from)->startOfDay() : $toDate->copy()->subMonths(11)->startOfMonth();

        $buckets = Deal::query()
            ->where('status', Deal::STATUS_WON)
            ->whereBetween('closed_at', [$fromDate, $toDate])
            ->selectRaw('date_trunc(?, closed_at) as bucket, COUNT(*) as won_count, COALESCE(SUM(amount), 0) as won_amount', [$period])
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get()
            ->map(fn ($row) => [
                'key' => Carbon::parse($row->bucket)->toDateString(),
                'label' => $this->label($period, Carbon::parse($row->bucket)),
                'won_count' => (int) $row->won_count,
                'won_amount' => (float) $row->won_amount,
            ])
            ->all();

        return [
            'period' => $period,
            'from' => $fromDate->toDateString(),
            'to' => $toDate->toDateString(),
            'buckets' => $buckets,
        ];
    }

    /**
     * High-level deal summary (win rate, average size and cycle time).
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $won = Deal::where('status', Deal::STATUS_WON)->count();
        $lost = Deal::where('status', Deal::STATUS_LOST)->count();
        $open = Deal::where('status', self::OPEN);
        $closed = $won + $lost;

        $avgCycle = Deal::query()
            ->where('status', Deal::STATUS_WON)
            ->whereNotNull('closed_at')
            ->selectRaw('AVG(EXTRACT(EPOCH FROM (closed_at - created_at)) / 86400.0) as avg_days')
            ->value('avg_days');

        return [
            'won_count' => $won,
            'lost_count' => $lost,
            'win_rate' => $closed > 0 ? round($won / $closed * 100, 1) : 0.0,
            'avg_deal_size' => round((float) Deal::where('status', Deal::STATUS_WON)->avg('amount'), 2),
            'avg_cycle_days' => $avgCycle !== null ? round((float) $avgCycle, 1) : 0.0,
            'open_count' => (clone $open)->count(),
            'open_amount' => (float) (clone $open)->sum('amount'),
        ];
    }

    private function label(string $period, Carbon $date): string
    {
        return match ($period) {
            'year' => $date->format('Y'),
            'quarter' => 'Q' . (int) ceil($date->month / 3) . ' ' . $date->format('Y'),
            default => $date->translatedFormat('M Y'),
        };
    }
}
