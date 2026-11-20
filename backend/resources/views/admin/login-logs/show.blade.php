@extends('admin.layout')

@section('title', 'Запись журнала входов')

@section('content')
<div class="form-card" style="max-width: 900px;">
    <div style="display: flex; justify-content: space-between; align-items: center;">
        <h2>🔑 Запись #{{ $log->id }}</h2>
        <a href="{{ route('admin.login-logs.index') }}" class="btn btn-secondary btn-sm">← К списку</a>
    </div>

    <table style="width: 100%; margin: 1rem 0; border-collapse: collapse;">
        <tbody>
            <tr><td style="padding: 0.5rem 0; color: #6B778C; width: 180px;">Дата</td><td>{{ $log->created_at?->format('d.m.Y H:i:s') }}</td></tr>
            <tr><td style="padding: 0.5rem 0; color: #6B778C;">Пользователь</td><td>{{ $log->user?->full_name ?? '—' }} (ID: {{ $log->user_id ?? '—' }})</td></tr>
            <tr><td style="padding: 0.5rem 0; color: #6B778C;">Email</td><td>{{ $log->email ?? '—' }}</td></tr>
            <tr><td style="padding: 0.5rem 0; color: #6B778C;">Событие</td><td><span class="badge badge-info">{{ $log->event }}</span></td></tr>
            <tr><td style="padding: 0.5rem 0; color: #6B778C;">Канал</td><td>{{ $log->channel }}</td></tr>
            <tr><td style="padding: 0.5rem 0; color: #6B778C;">Успешно</td><td>{{ $log->successful ? 'Да' : 'Нет' }}</td></tr>
            <tr><td style="padding: 0.5rem 0; color: #6B778C;">Причина</td><td>{{ $log->reason ?? '—' }}</td></tr>
            <tr><td style="padding: 0.5rem 0; color: #6B778C;">IP</td><td>{{ $log->ip_address ?? '—' }}</td></tr>
            <tr><td style="padding: 0.5rem 0; color: #6B778C;">User-Agent</td><td style="word-break: break-all;">{{ $log->user_agent ?? '—' }}</td></tr>
        </tbody>
    </table>

    <h3 style="margin-top: 1.5rem;">Дополнительно</h3>
    <pre style="background: #FAFBFC; border: 1px solid #DFE1E6; border-radius: 6px; padding: 1rem; overflow: auto;">{{ json_encode($log->meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '—' }}</pre>
</div>
@endsection
