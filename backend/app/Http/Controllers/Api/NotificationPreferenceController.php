<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotificationPreference;
use App\Models\NotificationSetting;
use App\Services\Tasksbase\NotificationPreferenceResolver;
use App\Support\Notifications\NotificationChannel;
use App\Support\Notifications\NotificationType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class NotificationPreferenceController extends Controller
{
    public function index(Request $request)
    {
        $userId = (int) Auth::id();

        $preferences = collect(NotificationType::cases())
            ->mapWithKeys(function (NotificationType $type) use ($userId) {
                $row = NotificationPreference::where('user_id', $userId)
                    ->where('type', $type->value)
                    ->first();

                return [$type->value => [
                    'in_app' => $row?->in_app ?? $type->defaultsFor(NotificationChannel::InApp),
                    'email' => $row?->email ?? $type->defaultsFor(NotificationChannel::Email),
                    'websocket' => $row?->websocket ?? $type->defaultsFor(NotificationChannel::Push),
                    'configurable' => $type->isUserConfigurable(),
                    'label' => $type->label(),
                    'group' => $type->group(),
                ]];
            });

        $setting = NotificationSetting::firstOrCreate(
            ['user_id' => $userId],
            ['digest_frequency' => config('notifications.digest.default_frequency', 'off')],
        );

        return response()->json([
            'types' => NotificationType::grouped(),
            'channels' => collect(NotificationChannel::all())->map(fn (NotificationChannel $channel) => [
                'value' => $channel->value,
                'label' => $channel->label(),
            ])->values(),
            'preferences' => $preferences,
            'digest_frequency' => $setting->digest_frequency,
            'digest_frequencies' => [
                ['value' => 'off', 'label' => 'Выключено'],
                ['value' => 'daily', 'label' => 'Ежедневно'],
                ['value' => 'weekly', 'label' => 'Еженедельно'],
            ],
        ]);
    }

    public function update(Request $request, NotificationPreferenceResolver $resolver)
    {
        $validated = $request->validate([
            'preferences' => 'sometimes|array',
            'preferences.*.type' => ['required', Rule::in(NotificationType::values())],
            'preferences.*.in_app' => 'sometimes|boolean',
            'preferences.*.email' => 'sometimes|boolean',
            'preferences.*.websocket' => 'sometimes|boolean',
            'digest_frequency' => ['sometimes', Rule::in(NotificationSetting::FREQUENCIES)],
        ]);

        $userId = (int) Auth::id();

        foreach ($validated['preferences'] ?? [] as $preference) {
            $type = NotificationType::from($preference['type']);

            if (! $type->isUserConfigurable()) {
                continue;
            }

            NotificationPreference::updateOrCreate(
                ['user_id' => $userId, 'type' => $type->value],
                [
                    'in_app' => (bool) ($preference['in_app'] ?? true),
                    'email' => (bool) ($preference['email'] ?? true),
                    'websocket' => (bool) ($preference['websocket'] ?? true),
                ],
            );
        }

        if (isset($validated['digest_frequency'])) {
            NotificationSetting::updateOrCreate(
                ['user_id' => $userId],
                ['digest_frequency' => $validated['digest_frequency']],
            );
        }

        $resolver->flush();

        return $this->index($request);
    }
}
