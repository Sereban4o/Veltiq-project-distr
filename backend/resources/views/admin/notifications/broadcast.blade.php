@extends('admin.layout')

@section('title', 'Рассылка уведомлений')

@section('content')
<div class="form-card" style="max-width: 760px;">
    <h2>📨 Рассылка уведомлений</h2>
    <p style="color: #6B778C; font-size: 0.875rem;">
        Активных получателей сейчас: <strong>{{ $recipients }}</strong>.
        Уведомление сохраняется в ленте у каждого получателя, отправляется в realtime и push
        (с учётом персональных настроек каналов).
    </p>

    <form method="POST" action="{{ route('admin.notifications.broadcast.send') }}">
        @csrf

        <div class="form-group">
            <label>Текст уведомления</label>
            <textarea name="message" rows="4" required maxlength="1000">{{ old('message') }}</textarea>
            @error('message')
                <p style="color: #DE350B; font-size: 0.8125rem; margin: 0.35rem 0 0;">{{ $message }}</p>
            @enderror
        </div>

        <div class="form-group" style="display: flex; gap: 1rem; flex-wrap: wrap;">
            <div style="flex: 1; min-width: 220px;">
                <label>Заголовок push</label>
                <input type="text" name="title" value="{{ old('title', 'Tasksbase') }}" maxlength="120">
            </div>
            <div style="flex: 1; min-width: 200px;">
                <label>Тип</label>
                <select name="type">
                    <option value="system" @selected(old('type', 'system') === 'system')>system</option>
                    @foreach($types as $available)
                        <option value="{{ $available }}" @selected(old('type') === $available)>{{ $available }}</option>
                    @endforeach
                </select>
            </div>
            <div style="flex: 1; min-width: 200px;">
                <label>Роль</label>
                <select name="role">
                    <option value="">Все роли</option>
                    @foreach($roles as $role)
                        <option value="{{ $role }}" @selected(old('role') === $role)>{{ $role }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="form-group">
            <label>
                <input type="checkbox" name="include_inactive" value="1" @checked(old('include_inactive'))>
                Включить пользователей не в статусе active
            </label>
        </div>

        <button type="submit" class="btn btn-primary">📨 Отправить</button>
    </form>
</div>

<div class="form-card" style="margin-top: 1.5rem;">
    <h2>🗂️ Очередь уведомлений</h2>

    <form method="GET" class="filters" style="flex-wrap: wrap;">
        <input type="text" name="q" value="{{ $filters['q'] }}" placeholder="Пользователь или тип">
        <select name="type">
            <option value="">Все типы</option>
            @foreach($types as $available)
                <option value="{{ $available }}" @selected($filters['type'] === $available)>{{ $available }}</option>
            @endforeach
        </select>
        <button class="btn btn-primary btn-sm" type="submit">🔍 Найти</button>
        <a href="{{ route('admin.notifications.broadcast') }}" class="btn btn-secondary btn-sm">✕ Сбросить</a>
    </form>

    <div class="data-table">
        <table>
            <thead>
            <tr>
                <th>Дата</th>
                <th>Получатель</th>
                <th>Тип</th>
                <th>Текст</th>
                <th>Прочитано</th>
            </tr>
            </thead>
            <tbody>
            @forelse($notifications as $notification)
                @php($data = (array) $notification->data)
                <tr>
                    <td>{{ $notification->created_at?->format('d.m.Y H:i') }}</td>
                    <td>
                        {{ $notification->user?->full_name ?? '—' }}<br>
                        <span style="color: #6B778C; font-size: 0.75rem;">{{ $notification->user?->email }}</span>
                    </td>
                    <td><span class="badge badge-info">{{ $notification->type }}</span></td>
                    <td>{{ \Illuminate\Support\Str::limit((string) ($data['summary'] ?? $data['message'] ?? '—'), 160) }}</td>
                    <td>{{ $notification->read ? '✓' : '—' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" style="text-align: center; padding: 2rem; color: #6B778C;">Уведомлений нет</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="pagination">{{ $notifications->links() }}</div>
</div>
@endsection
