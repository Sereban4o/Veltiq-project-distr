@extends('admin.layout')

@section('title', 'Запись журнала действий')

@section('content')
<div class="form-card" style="max-width: 900px;">
    <div style="display: flex; justify-content: space-between; align-items: center;">
        <h2>🕵️ Запись #{{ $log->id }}</h2>
        <a href="{{ route('admin.audit.index') }}" class="btn btn-secondary btn-sm">← К списку</a>
    </div>

    <table style="width: 100%; margin: 1rem 0; border-collapse: collapse;">
        <tbody>
            <tr><td style="padding: 0.5rem 0; color: #6B778C; width: 180px;">Дата</td><td>{{ $log->created_at?->format('d.m.Y H:i:s') }}</td></tr>
            <tr><td style="padding: 0.5rem 0; color: #6B778C;">Пользователь</td><td>{{ $log->user?->full_name ?? '—' }}</td></tr>
            <tr><td style="padding: 0.5rem 0; color: #6B778C;">Действие</td><td><span class="badge badge-info">{{ $log->action }}</span></td></tr>
            <tr><td style="padding: 0.5rem 0; color: #6B778C;">Объект</td><td>{{ $log->subject_type }} @if($log->subject_id) #{{ $log->subject_id }} @endif</td></tr>
            <tr><td style="padding: 0.5rem 0; color: #6B778C;">Описание</td><td>{{ $log->description ?? '—' }}</td></tr>
            <tr><td style="padding: 0.5rem 0; color: #6B778C;">IP</td><td>{{ $log->ip_address ?? '—' }}</td></tr>
            <tr><td style="padding: 0.5rem 0; color: #6B778C;">User-Agent</td><td style="word-break: break-all;">{{ $log->user_agent ?? '—' }}</td></tr>
        </tbody>
    </table>

    <h3 style="margin-top: 1.5rem;">Изменения</h3>
    <pre style="background: #FAFBFC; border: 1px solid #DFE1E6; border-radius: 6px; padding: 1rem; overflow: auto;">{{ json_encode($log->changes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '—' }}</pre>

    <h3 style="margin-top: 1.5rem;">Свойства</h3>
    <pre style="background: #FAFBFC; border: 1px solid #DFE1E6; border-radius: 6px; padding: 1rem; overflow: auto;">{{ json_encode($log->properties, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '—' }}</pre>
</div>
@endsection
