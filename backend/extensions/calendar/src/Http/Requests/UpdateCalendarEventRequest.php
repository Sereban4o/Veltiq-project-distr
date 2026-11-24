<?php

namespace App\Extensions\Calendar\Http\Requests;

class UpdateCalendarEventRequest extends CalendarEventRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->baseRules(partial: true);
    }
}
