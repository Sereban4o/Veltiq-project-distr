<?php

namespace App\Extensions\Calendar\Http\Controllers\Concerns;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

trait ResolvesCalendarRange
{
    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    protected function resolveRange(Request $request): array
    {
        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
        ]);

        $timezone = config('app.timezone', 'UTC');
        $from = CarbonImmutable::parse($validated['from'], $timezone)->startOfDay()->utc();
        $to = CarbonImmutable::parse($validated['to'], $timezone)->endOfDay()->utc();

        $maxDays = (int) config('calendar.max_range_days', 366);
        if ($from->diffInDays($to) > $maxDays) {
            abort(422, "Диапазон дат не должен превышать {$maxDays} дней");
        }

        return [$from, $to];
    }
}
