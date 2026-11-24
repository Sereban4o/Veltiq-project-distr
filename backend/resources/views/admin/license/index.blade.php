@extends('admin.layout')

@section('title', 'Лицензия')

@section('content')
<h1 style="margin-bottom: 1.5rem; color: #172B4D;">🔑 Лицензия</h1>

@if($errors->any())
    <div class="alert alert-error">
        <ul style="margin: 0; padding-left: 1.25rem;">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

@if(session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
@endif

@if(! $coreReachable)
    <div class="alert alert-error">
        Ядро лицензий недоступно. Проверьте <code>CORE_URL</code> и <code>CORE_TOKEN</code>
        в <code>backend/.env.production</code> и что контейнер <code>core</code> запущен.
    </div>
@endif

<div class="form-card" style="max-width: 100%; margin-bottom: 2rem;">
    <h2>Состояние</h2>
    <table style="width: 100%; border-collapse: collapse; font-size: 0.9375rem;">
        <tbody>
            <tr>
                <td style="padding: 0.4rem 0; color: #6B778C; width: 220px;">Состояние</td>
                <td>
                    <span class="badge {{ $app['access'] === 'full' ? 'badge-success' : ($app['access'] === 'read_only' ? 'badge-info' : 'badge-danger') }}">
                        {{ $app['state'] }}
                    </span>
                    @if($app['source'] !== 'core')
                        <span style="color: #6B778C; font-size: 0.8125rem;">(источник: {{ $app['source'] }})</span>
                    @endif
                </td>
            </tr>
            <tr>
                <td style="padding: 0.4rem 0; color: #6B778C;">Доступ</td>
                <td>{{ $app['access'] }}</td>
            </tr>
            <tr>
                <td style="padding: 0.4rem 0; color: #6B778C;">Истекает</td>
                <td>{{ $app['expires_at'] ?? '—' }}</td>
            </tr>
            <tr>
                <td style="padding: 0.4rem 0; color: #6B778C;">Фичи</td>
                <td>{{ $app['features'] === [] ? '—' : implode(', ', $app['features']) }}</td>
            </tr>
            <tr>
                <td style="padding: 0.4rem 0; color: #6B778C;">Расширения</td>
                <td>{{ $app['modules'] === [] ? '—' : implode(', ', $app['modules']) }}</td>
            </tr>
            <tr>
                <td style="padding: 0.4rem 0; color: #6B778C;">Enforcement</td>
                <td>{{ $enforced ? 'включён' : 'выключен (наблюдение)' }}</td>
            </tr>
            @if($core)
                <tr>
                    <td style="padding: 0.4rem 0; color: #6B778C;">Отпечаток</td>
                    <td style="font-family: monospace;">{{ $core['fingerprint'] ?? '—' }}</td>
                </tr>
                <tr>
                    <td style="padding: 0.4rem 0; color: #6B778C;">Лицензия</td>
                    <td>
                        {{ $core['status']['lic'] ?? '—' }}
                        @if(! empty($core['status']['customer'])) · {{ $core['status']['customer'] }} @endif
                        @if(! empty($core['status']['edition'])) · {{ $core['status']['edition'] }} @endif
                    </td>
                </tr>
                @if(($core['clock_tamper'] ?? false) === true)
                    <tr>
                        <td style="padding: 0.4rem 0; color: #6B778C;">Часы</td>
                        <td><span class="badge badge-danger">обнаружен откат часов</span></td>
                    </tr>
                @endif
            @endif
        </tbody>
    </table>
</div>

<div class="form-card" style="max-width: 100%; margin-bottom: 2rem;">
    <h2>Активация</h2>
    <p style="color: #6B778C; font-size: 0.875rem; margin-bottom: 1.25rem;">
        Онлайн: просто загрузите файл лицензии. Офлайн (закрытый контур): получите код активации,
        передайте его вендору и загрузите полученный подписанный файл.
    </p>

    <form method="POST" action="{{ route('admin.license.request') }}" style="margin-bottom: 1rem;">
        @csrf
        <button type="submit" class="btn btn-secondary">Получить код активации</button>
    </form>

    @if($requestCode)
        <pre style="background: #F4F5F7; padding: 0.75rem; border-radius: 4px; overflow: auto; font-size: 0.8125rem;">{{ json_encode($requestCode, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
    @endif

    <form method="POST" action="{{ route('admin.license.activate') }}" enctype="multipart/form-data">
        @csrf
        <div class="form-group">
            <label>Файл лицензии</label>
            <input type="file" name="license" accept=".json,.lic,application/json">
        </div>
        <div class="form-group">
            <label>…или вставьте содержимое</label>
            <textarea name="license_text" rows="5" style="width: 100%; font-family: monospace; font-size: 0.8125rem;" placeholder='{"payload":"…","sig":"…"}'></textarea>
        </div>
        <button type="submit" class="btn btn-primary">Установить лицензию</button>
    </form>
</div>

<div class="form-card" style="max-width: 100%;">
    <h2>Список отзыва (CRL)</h2>
    <p style="color: #6B778C; font-size: 0.875rem; margin-bottom: 1rem;">
        Подписанный список отозванных лицензий. Требуется только для офлайн-контуров.
    </p>
    <form method="POST" action="{{ route('admin.license.crl') }}" enctype="multipart/form-data">
        @csrf
        <div class="form-group">
            <label>Файл CRL</label>
            <input type="file" name="crl" accept=".json,.lic,application/json" required>
        </div>
        <button type="submit" class="btn btn-secondary">Загрузить CRL</button>
    </form>
</div>
@endsection
