<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\NotificationDigestMail;
use App\Models\Notification;
use App\Models\Setting;
use App\Support\Notifications\NotificationType;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Throwable;

class NotificationSettingsController extends Controller
{
    public function index()
    {
        $settings = [
            'notifications_digest_enabled' => Setting::get('notifications_digest_enabled', config('notifications.digest.enabled') ? '1' : '0'),
            'mail_from_address' => Setting::get('mail_from_address') ?? config('mail.from.address'),
            'mail_from_name' => Setting::get('mail_from_name') ?? config('mail.from.name'),
            'notification_retention_days' => Setting::get('notification_retention_days', (string) config('notifications.retention.notifications_days')),
        ];

        return view('admin.notifications.settings', compact('settings'));
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'notifications_digest_enabled' => 'nullable|boolean',
            'mail_from_address' => 'nullable|email|max:255',
            'mail_from_name' => 'nullable|string|max:255',
            'notification_retention_days' => 'nullable|integer|min:1|max:3650',
        ]);

        Setting::put('notifications_digest_enabled', $request->boolean('notifications_digest_enabled') ? '1' : '0');
        Setting::put('mail_from_address', $validated['mail_from_address'] ?? null);
        Setting::put('mail_from_name', $validated['mail_from_name'] ?? null);
        Setting::put('notification_retention_days', (string) ($validated['notification_retention_days'] ?? config('notifications.retention.notifications_days')));

        return redirect()->route('admin.notifications.index')->with('success', 'Настройки уведомлений сохранены');
    }

    public function test(Request $request)
    {
        $user = $request->user();

        $notifications = Notification::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', now()->subDay())
            ->latest()
            ->limit(10)
            ->get();

        if ($notifications->isEmpty()) {
            $notifications = new Collection([$this->sampleNotification()]);
        }

        try {
            Mail::to($user->email)->send(new NotificationDigestMail($user, $notifications, 'daily'));
        } catch (Throwable $e) {
            return back()->with('error', 'Не удалось отправить тестовый дайджест: ' . $e->getMessage());
        }

        return back()->with('success', 'Тестовый дайджест отправлен на ' . $user->email);
    }

    private function sampleNotification(): Notification
    {
        $notification = new Notification([
            'type' => NotificationType::System->value,
            'data' => ['summary' => 'Это тестовое уведомление из админ-панели.'],
        ]);
        $notification->created_at = now();

        return $notification;
    }
}
