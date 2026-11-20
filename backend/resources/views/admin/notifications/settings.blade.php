@extends('admin.layout')

@section('title', 'Настройки уведомлений')

@section('content')
<div class="form-card" style="max-width: 700px;">
    <h2>🔔 Настройки уведомлений</h2>

    <form method="POST" action="{{ route('admin.notifications.update') }}">
        @csrf

        <div class="form-group">
            <label>
                <input type="checkbox" name="notifications_digest_enabled" value="1"
                       @checked(($settings['notifications_digest_enabled'] ?? '0') === '1')>
                Включить email-дайджесты
            </label>
            <p style="color: #6B778C; font-size: 0.8125rem; margin: 0.35rem 0 0;">
                Пользователи выбирают частоту (ежедневно/еженедельно) в своём профиле.
            </p>
        </div>

        <div class="form-group">
            <label>Email отправителя</label>
            <input type="email" name="mail_from_address" value="{{ old('mail_from_address', $settings['mail_from_address']) }}" placeholder="noreply@example.com">
        </div>

        <div class="form-group">
            <label>Имя отправителя</label>
            <input type="text" name="mail_from_name" value="{{ old('mail_from_name', $settings['mail_from_name']) }}">
        </div>

        <div class="form-group">
            <label>Хранить уведомления (дней)</label>
            <input type="number" name="notification_retention_days" min="1" max="3650"
                   value="{{ old('notification_retention_days', $settings['notification_retention_days']) }}">
        </div>

        <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
            <button type="submit" class="btn btn-primary">💾 Сохранить</button>
        </div>
    </form>
</div>

<div class="form-card" style="max-width: 700px; margin-top: 1.5rem;">
    <h2>✉️ Проверка отправки</h2>
    <p style="color: #6B778C; font-size: 0.875rem;">
        Отправит тестовый дайджест на ваш email ({{ auth()->user()->email }}).
    </p>
    <form method="POST" action="{{ route('admin.notifications.test') }}">
        @csrf
        <button type="submit" class="btn btn-secondary">Отправить тестовый дайджест</button>
    </form>
</div>
@endsection
