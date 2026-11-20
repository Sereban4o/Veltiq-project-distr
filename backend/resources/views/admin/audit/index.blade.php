@extends('admin.layout')

@section('title', 'Журнал действий')

@section('content')
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
    <h1 style="color: #172B4D;">🕵️ Журнал действий</h1>
    <a href="{{ route('admin.audit.export', request()->query()) }}" class="btn btn-secondary btn-sm">⬇️ Экспорт CSV</a>
</div>

<form method="GET" class="filters" style="flex-wrap: wrap;">
    <select name="user_id">
        <option value="">Все пользователи</option>
        @foreach($users as $user)
            <option value="{{ $user->id }}" @selected((int) request('user_id') === $user->id)>{{ $user->full_name }}</option>
        @endforeach
    </select>

    <select name="action">
        <option value="">Все действия</option>
        @foreach($actions as $action)
            <option value="{{ $action }}" @selected(request('action') === $action)>{{ $action }}</option>
        @endforeach
    </select>

    <select name="subject_type">
        <option value="">Все объекты</option>
        @foreach($subjectTypes as $type)
            <option value="{{ $type }}" @selected(request('subject_type') === $type)>{{ class_basename($type) }}</option>
        @endforeach
    </select>

    <input type="date" name="date_from" value="{{ request('date_from') }}">
    <input type="date" name="date_to" value="{{ request('date_to') }}">

    <input type="text" name="q" placeholder="Поиск..." value="{{ request('q') }}">

    <button type="submit" class="btn btn-primary btn-sm">🔍 Найти</button>
    <a href="{{ route('admin.audit.index') }}" class="btn btn-secondary btn-sm">✕ Сбросить</a>
</form>

<div class="data-table">
    <table>
        <thead>
            <tr>
                <th>Дата</th>
                <th>Пользователь</th>
                <th>Действие</th>
                <th>Объект</th>
                <th>IP</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse($logs as $log)
                <tr>
                    <td>{{ $log->created_at?->format('d.m.Y H:i:s') }}</td>
                    <td>{{ $log->user?->full_name ?? '—' }}</td>
                    <td><span class="badge badge-info">{{ $log->action }}</span></td>
                    <td>
                        {{ $log->subject_type ? class_basename($log->subject_type) : '—' }}
                        @if($log->subject_id) #{{ $log->subject_id }} @endif
                    </td>
                    <td>{{ $log->ip_address ?? '—' }}</td>
                    <td><a href="{{ route('admin.audit.show', $log->id) }}" class="btn btn-secondary btn-sm">👁️</a></td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" style="text-align: center; padding: 2rem; color: #6B778C;">Записей не найдено</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="pagination">{{ $logs->links() }}</div>
@endsection
