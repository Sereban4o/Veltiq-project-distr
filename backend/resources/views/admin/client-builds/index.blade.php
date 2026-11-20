@extends('admin.layout')

@section('title', 'Сборки клиентов')

@section('content')
<h1 style="margin-bottom: 1.5rem; color: #172B4D;">🏗️ Сборки клиентов (preset)</h1>

@if($errors->any())
    <div class="alert alert-error">
        <ul style="margin: 0; padding-left: 1.25rem;">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="form-card" style="max-width: 100%; margin-bottom: 2rem;">
    <h2>Подключение GitHub Actions</h2>
    <p style="color: #6B778C; font-size: 0.875rem;">
        Сборка APK/MSI выполняется в CI (на сервере нет Android/Windows toolchain). Нужен токен с правом
        <code>actions:write</code> на репозиторий.
    </p>
    <form method="POST" action="{{ route('admin.client-builds.settings') }}">
        @csrf
        @method('PUT')
        <div style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-end;">
            <div class="form-group" style="margin-bottom: 0; min-width: 240px;">
                <label>Репозиторий (owner/name)</label>
                <input type="text" name="repo" value="{{ old('repo', $settings['repo']) }}" placeholder="owner/repo">
            </div>
            <div class="form-group" style="margin-bottom: 0; min-width: 200px;">
                <label>Workflow-файл</label>
                <input type="text" name="workflow" value="{{ old('workflow', $settings['workflow']) }}" placeholder="build-clients.yml">
            </div>
            <div class="form-group" style="margin-bottom: 0; min-width: 140px;">
                <label>Ref (ветка/тег)</label>
                <input type="text" name="ref" value="{{ old('ref', $settings['ref']) }}" placeholder="main">
            </div>
            <div class="form-group" style="margin-bottom: 0; min-width: 240px; flex: 1;">
                <label>Токен GitHub {{ $settings['token_set'] ? '(сохранён)' : '' }}</label>
                <input type="password" name="token" value="" autocomplete="new-password"
                       placeholder="{{ $settings['token_set'] ? '•••••••• (оставьте пустым)' : 'ghp_…' }}">
            </div>
            <div class="form-group" style="margin-bottom: 0;">
                <label style="display: flex; align-items: center; gap: 0.4rem;">
                    <input type="checkbox" name="clear_token" value="1">
                    Удалить токен
                </label>
            </div>
            <div class="form-group" style="margin-bottom: 0;">
                <button type="submit" class="btn btn-secondary">💾 Сохранить</button>
            </div>
        </div>
    </form>
</div>

<div class="form-card" style="max-width: 100%; margin-bottom: 2rem;">
    <h2>Запустить сборку</h2>
    <form method="POST" action="{{ route('admin.client-builds.store') }}">
        @csrf
        <div style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-end;">
            <div class="form-group" style="margin-bottom: 0; min-width: 150px;">
                <label>Бренд</label>
                <input type="text" name="brand" value="{{ old('brand', 'example') }}" required>
            </div>
            <div class="form-group" style="margin-bottom: 0; min-width: 200px;">
                <label>Название приложения</label>
                <input type="text" name="app_name" value="{{ old('app_name') }}" placeholder="Veltiq">
            </div>
            <div class="form-group" style="margin-bottom: 0; min-width: 200px;">
                <label>Сервер (host)</label>
                <input type="text" name="server_host" value="{{ old('server_host') }}" placeholder="veltiq.ru" required>
            </div>
            <div class="form-group" style="margin-bottom: 0; min-width: 100px;">
                <label>Порт</label>
                <input type="number" name="server_port" min="1" max="65535" value="{{ old('server_port', 443) }}" required>
            </div>
            <div class="form-group" style="margin-bottom: 0;">
                <label style="display: flex; align-items: center; gap: 0.4rem;">
                    <input type="checkbox" name="server_https" value="1" @checked(old('server_https', true))>
                    HTTPS
                </label>
            </div>
        </div>

        <div style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-end; margin-top: 1rem;">
            <div class="form-group" style="margin-bottom: 0; min-width: 200px;">
                <label>ntfy host</label>
                <input type="text" name="ntfy_host" value="{{ old('ntfy_host') }}" placeholder="ntfy.veltiq.ru">
            </div>
            <div class="form-group" style="margin-bottom: 0; min-width: 100px;">
                <label>ntfy порт</label>
                <input type="number" name="ntfy_port" min="1" max="65535" value="{{ old('ntfy_port', 443) }}">
            </div>
            <div class="form-group" style="margin-bottom: 0; min-width: 160px;">
                <label>ntfy пользователь</label>
                <input type="text" name="ntfy_user" value="{{ old('ntfy_user') }}" autocomplete="off">
            </div>
            <div class="form-group" style="margin-bottom: 0; min-width: 160px;">
                <label>ntfy пароль</label>
                <input type="password" name="ntfy_pass" value="" autocomplete="new-password">
            </div>
            <div class="form-group" style="margin-bottom: 0;">
                <label style="display: flex; align-items: center; gap: 0.4rem;">
                    <input type="checkbox" name="ntfy_https" value="1" @checked(old('ntfy_https', true))>
                    ntfy HTTPS
                </label>
            </div>
            <div class="form-group" style="margin-bottom: 0;">
                <button type="submit" class="btn btn-primary">🚀 Запустить сборку</button>
            </div>
        </div>
    </form>
</div>

@if($runsError)
    <div class="alert alert-error">Не удалось получить статусы сборок: {{ $runsError }}</div>
@endif

<div class="data-table">
    <table>
        <thead>
            <tr>
                <th>Запуск</th>
                <th>Статус</th>
                <th>Результат</th>
                <th>Создан</th>
                <th>Ссылка</th>
            </tr>
        </thead>
        <tbody>
            @forelse($runs as $run)
                <tr>
                    <td>{{ $run['title'] ?: ('#' . $run['id']) }}</td>
                    <td><span class="badge badge-info">{{ $run['status'] ?: '—' }}</span></td>
                    <td>{{ $run['conclusion'] ?: '—' }}</td>
                    <td>
                        {{ $run['created_at'] ? \Illuminate\Support\Carbon::parse($run['created_at'])->format('d.m.Y H:i') : '—' }}
                    </td>
                    <td>
                        @if($run['url'])
                            <a href="{{ $run['url'] }}" target="_blank" rel="noopener">Открыть</a>
                        @else
                            —
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" style="text-align: center; padding: 2rem; color: #6B778C;">
                        Запусков пока нет (или GitHub не настроен)
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
