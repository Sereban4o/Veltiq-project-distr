@extends('admin.layout')

@section('title', 'Журнал входов')

@section('content')
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
    <h1 style="color: #172B4D;">🔑 Журнал входов</h1>
    <a href="{{ route('admin.login-logs.export', request()->query()) }}" class="btn btn-secondary btn-sm">⬇️ Экспорт CSV</a>
</div>

<form method="GET" class="filters" style="flex-wrap: wrap;">
    <select name="user_id">
        <option value="">Все пользователи</option>
        @foreach($users as $user)
            <option value="{{ $user->id }}" @selected((int) request('user_id') === $user->id)>{{ $user->full_name }}</option>
        @endforeach
    </select>

    <select name="event">
        <option value="">Все события</option>
        @foreach($events as $event)
            <option value="{{ $event }}" @selected(request('event') === $event)>{{ $event }}</option>
        @endforeach
    </select>

    <select name="channel">
        <option value="">Все каналы</option>
        @foreach($channels as $channel)
            <option value="{{ $channel }}" @selected(request('channel') === $channel)>{{ $channel }}</option>
        @endforeach
    </select>

    <select name="successful">
        <option value="">Успешность</option>
        <option value="1" @selected(request('successful') === '1')>Успешные</option>
        <option value="0" @selected(request('successful') === '0')>Неуспешные</option>
    </select>

    <input type="email" name="email" placeholder="Email" value="{{ request('email') }}">
    <input type="date" name="date_from" value="{{ request('date_from') }}">
    <input type="date" name="date_to" value="{{ request('date_to') }}">

    <button type="submit" class="btn btn-primary btn-sm">🔍 Найти</button>
    <a href="{{ route('admin.login-logs.index') }}" class="btn btn-secondary btn-sm">✕ Сбросить</a>
</form>

<div class="data-table">
    <table>
        <thead>
            <tr>
                <th>Дата</th>
                <th>Email</th>
                <th>Событие</th>
                <th>Канал</th>
                <th>Статус</th>
                <th>IP</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse($logs as $log)
                @php
                    $badge = match ($log->event) {
                        'login' => 'badge-success',
                        'failed' => 'badge-danger',
                        default => 'badge-info',
                    };
                @endphp
                <tr>
                    <td>{{ $log->created_at?->format('d.m.Y H:i:s') }}</td>
                    <td>{{ $log->email ?? '—' }}</td>
                    <td><span class="badge {{ $badge }}">{{ $log->event }}</span></td>
                    <td><span class="badge badge-info">{{ $log->channel }}</span></td>
                    <td>
                        @if($log->successful)
                            <span class="badge badge-success">OK</span>
                        @else
                            <span class="badge badge-danger">{{ $log->reason ?? 'fail' }}</span>
                        @endif
                    </td>
                    <td>{{ $log->ip_address ?? '—' }}</td>
                    <td><a href="{{ route('admin.login-logs.show', $log->id) }}" class="btn btn-secondary btn-sm">👁️</a></td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" style="text-align: center; padding: 2rem; color: #6B778C;">Записей не найдено</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="pagination">{{ $logs->links() }}</div>
@endsection
