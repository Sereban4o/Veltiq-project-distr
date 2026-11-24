<?php

namespace Database\Factories;

use App\Models\User;
use App\Extensions\Calendar\Models\CalendarEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CalendarEvent>
 */
class CalendarEventFactory extends Factory
{
    protected $model = CalendarEvent::class;

    public function definition(): array
    {
        $startsAt = now()->addDay()->setTime(10, 0);

        return [
            'user_id' => User::factory(),
            'organizer_id' => null,
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->paragraph(),
            'location' => null,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHour(),
            'all_day' => false,
            'timezone' => 'UTC',
            'color' => '#0052CC',
            'visibility' => CalendarEvent::VISIBILITY_PRIVATE,
            'source' => CalendarEvent::SOURCE_LOCAL,
        ];
    }
}
