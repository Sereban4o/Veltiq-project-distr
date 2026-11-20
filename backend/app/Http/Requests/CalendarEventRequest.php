<?php

namespace App\Http\Requests;

use App\Services\Calendar\RecurrenceExpander;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

abstract class CalendarEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $data = $this->all();

        if (empty($data['starts_at']) && ! empty($data['event_date'])) {
            $timezone = $data['timezone'] ?? config('app.timezone', 'UTC');
            $data['starts_at'] = Carbon::parse($data['event_date'], $timezone)
                ->startOfDay()
                ->utc()
                ->format('Y-m-d H:i:s');
            $data['all_day'] = true;
        }

        if (! empty($data['starts_at']) && empty($data['timezone'])) {
            $data['timezone'] = config('app.timezone', 'UTC');
        }

        if (isset($data['rrule']) && is_string($data['rrule'])) {
            $normalised = preg_replace('/^RRULE:/i', '', trim($data['rrule']));
            $data['rrule'] = $normalised !== '' ? $normalised : null;
        }

        $this->replace($data);
    }

    /**
     * @return array<string, mixed>
     */
    protected function baseRules(bool $partial): array
    {
        $required = $partial ? 'sometimes' : 'required_without:event_date';

        return [
            'title' => $partial ? 'sometimes|string|max:255' : 'required|string|max:255',
            'description' => 'nullable|string|max:65000',
            'location' => 'nullable|string|max:255',
            'starts_at' => "{$required}|date",
            'event_date' => 'sometimes|date',
            'ends_at' => 'nullable|date',
            'all_day' => 'sometimes|boolean',
            'timezone' => ['nullable', 'string', 'max:64', $this->timezoneRule()],
            'rrule' => 'nullable|string|max:1000',
            'exdates' => 'nullable|array',
            'exdates.*' => 'date',
            'color' => 'nullable|string|max:20',
            'visibility' => 'sometimes|in:private,public',
            'issue_ids' => 'nullable|array',
            'issue_ids.*' => 'integer|exists:issues,id',
            'deal_ids' => 'nullable|array',
            'deal_ids.*' => 'integer|exists:crm_deals,id',
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $starts = $this->input('starts_at');
                $ends = $this->input('ends_at');

                if ($starts && $ends && Carbon::parse($ends)->lt(Carbon::parse($starts))) {
                    $validator->errors()->add('ends_at', 'Дата окончания не может быть раньше даты начала');
                }

                $rrule = $this->input('rrule');
                if (! is_string($rrule) || $rrule === '') {
                    return;
                }

                if (preg_match('/COUNT=(\d+)/i', $rrule, $match) && (int) $match[1] > 1000) {
                    $validator->errors()->add('rrule', 'Количество повторений не может превышать 1000');

                    return;
                }

                $timezone = $this->input('timezone') ?: config('app.timezone', 'UTC');
                $start = Carbon::parse($starts ?: $this->input('event_date'), $timezone);

                if (preg_match('/UNTIL=([0-9TZ]+)/i', $rrule, $match)) {
                    try {
                        if (Carbon::parse($match[1])->lt($start)) {
                            $validator->errors()->add('rrule', 'Дата завершения повторения раньше начала события');

                            return;
                        }
                    } catch (\Throwable) {
                        // The rule validator below reports malformed UNTIL values.
                    }
                }

                try {
                    app(RecurrenceExpander::class)->validateRule($rrule, $start, $timezone);
                } catch (InvalidArgumentException $e) {
                    $validator->errors()->add('rrule', $e->getMessage());
                }
            },
        ];
    }

    private function timezoneRule(): callable
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if ($value === null || $value === '') {
                return;
            }

            if (! in_array($value, timezone_identifiers_list(), true)) {
                $fail('Некорректная таймзона');
            }
        };
    }
}
