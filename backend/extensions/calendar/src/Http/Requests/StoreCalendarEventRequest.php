<?php

namespace App\Extensions\Calendar\Http\Requests;

class StoreCalendarEventRequest extends CalendarEventRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->baseRules(partial: false);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Название события обязательно',
            'starts_at.required_without' => 'Укажите дату и время начала',
        ];
    }
}
