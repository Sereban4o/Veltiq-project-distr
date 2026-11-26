@extends('admin.layout')

@section('title', 'Обновления системы')

@section('content')
<h1 style="margin-bottom: 1.5rem; color: #172B4D;">🔄 Обновления системы</h1>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-error">{{ session('error') }}</div>@endif
@if($errors->any())
    <div class="alert alert-error">
        <ul style="margin: 0; padding-left: 1.25rem;">
            @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
        </ul>
    </div>
@endif

@if(! $status['configured'])
    <div class="alert alert-error">
        Сервис обновлений не настроен. В <code>backend/.env.production</code> задайте
        <code>UPDATE_SERVICE_MODE=client</code>, <code>UPDATE_SERVICE_URL</code>
        (например, <code>https://update.veltiq.ru</code>) и <code>UPDATE_SERVICE_TOKEN</code>,
        затем выполните <code>php artisan config:clear</code>.
    </div>
@endif

<div class="form-card" style="max-width: 100%; margin-bottom: 2rem;">
    <h2>Состояние</h2>
    <table style="width: 100%; border-collapse: collapse; font-size: 0.9375rem;">
        <tbody>
            <tr>
                <td style="padding: 0.4rem 0; color: #6B778C; width: 220px;">Сервис</td>
                <td>{{ $status['service_url'] !== '' ? $status['service_url'] : '—' }}</td>
            </tr>
            <tr>
                <td style="padding: 0.4rem 0; color: #6B778C;">Канал</td>
                <td>{{ $status['channel'] }}</td>
            </tr>
            <tr>
                <td style="padding: 0.4rem 0; color: #6B778C;">Текущая версия</td>
                <td>{{ $status['current_version'] }}</td>
            </tr>
            <tr>
                <td style="padding: 0.4rem 0; color: #6B778C;">Доступная версия</td>
                <td>{{ $status['latest']['version'] ?? '—' }}</td>
            </tr>
            <tr>
                <td style="padding: 0.4rem 0; color: #6B778C;">Обновление</td>
                <td>
                    @if($status['update_available'])
                        <span class="badge badge-success">доступно {{ $status['latest']['version'] ?? '' }}</span>
                    @else
                        <span class="badge badge-info">нет</span>
                    @endif
                </td>
            </tr>
            <tr>
                <td style="padding: 0.4rem 0; color: #6B778C;">Ошибка проверки</td>
                <td>
                    @if($status['check_error'])
                        <span style="color: #DE350B;">{{ $status['check_error'] }}</span>
                        <span style="color: #6B778C; font-size: 0.8125rem;">(проверьте UPDATE_SERVICE_URL / UPDATE_SERVICE_TOKEN)</span>
                    @else
                        —
                    @endif
                </td>
            </tr>
            <tr>
                <td style="padding: 0.4rem 0; color: #6B778C;">Проверено</td>
                <td>{{ $status['checked_at'] ?? '—' }}</td>
            </tr>
            <tr>
                <td style="padding: 0.4rem 0; color: #6B778C;">Применение</td>
                <td>{{ $allowApply ? 'разрешено' : 'отключено (UPDATE_SERVICE_ALLOW_APPLY=false)' }}</td>
            </tr>
            <tr>
                <td style="padding: 0.4rem 0; color: #6B778C;">Команда</td>
                <td><code style="font-size: 0.8125rem;">{{ $applyCommand !== '' ? \Illuminate\Support\Str::limit($applyCommand, 160) : '—' }}</code></td>
            </tr>
        </tbody>
    </table>

    @if(is_array($status['latest']) && ! empty($status['latest']['notes']))
        <div style="margin-top: 1rem;">
            <div style="color: #6B778C; font-size: 0.8125rem; margin-bottom: 0.25rem;">Что нового</div>
            <div style="white-space: pre-wrap;">{{ $status['latest']['notes'] }}</div>
        </div>
    @endif

    <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; margin-top: 1.25rem;">
        <form method="POST" action="{{ route('admin.system-update.check') }}">
            @csrf
            <button class="btn btn-secondary" type="submit">Проверить сейчас</button>
        </form>
        <form method="POST" action="{{ route('admin.system-update.apply') }}"
              onsubmit="return confirm('Применить обновление? Инсталляция будет перезапущена.');">
            @csrf
            <button class="btn btn-primary" type="submit" @disabled(! $allowApply || ! $status['update_available'])>
                Применить обновление
            </button>
        </form>
    </div>
</div>

<div class="form-card" style="max-width: 100%;">
    <h2>Журнал</h2>
    @if($running->isNotEmpty())
        <div style="background: #FFFAE6; color: #7A5B00; padding: 0.5rem 0.75rem; border-radius: 6px; margin-bottom: 0.75rem;">
            Есть незавершённые применения (pending) — возможно, обновление выполняется прямо сейчас.
        </div>
    @endif
    <table style="width: 100%; border-collapse: collapse; font-size: 0.9375rem;">
        <thead>
            <tr>
                <th style="text-align: left; padding: 0.4rem 0;">Дата</th>
                <th style="text-align: left;">С версии</th>
                <th style="text-align: left;">На версию</th>
                <th style="text-align: left;">Статус</th>
                <th style="text-align: left;">Сообщение</th>
            </tr>
        </thead>
        <tbody>
        @forelse($logs as $log)
            <tr>
                <td style="padding: 0.4rem 0;">{{ $log->created_at?->format('d.m.Y H:i') }}</td>
                <td>{{ $log->from_version ?? '—' }}</td>
                <td>{{ $log->to_version ?? '—' }}</td>
                <td>
                    <span class="badge {{ $log->status === 'success' ? 'badge-success' : ($log->status === 'failed' ? 'badge-danger' : 'badge-info') }}">{{ $log->status }}</span>
                </td>
                <td style="color: #6B778C; font-size: 0.8125rem;">{{ \Illuminate\Support\Str::limit((string) $log->message, 200) }}</td>
            </tr>
        @empty
            <tr><td colspan="5" style="color: #6B778C;">Записей нет.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
