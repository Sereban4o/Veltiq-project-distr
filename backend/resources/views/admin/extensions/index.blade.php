@extends('admin.layout')

@section('title', 'Расширения')

@section('content')
<h1 style="margin-bottom: 1.5rem; color: #172B4D;">🧱 Расширения</h1>

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

@if(! $canInstall)
    <div class="alert alert-error">
        Установка и изменение расширений недоступны: нет действующей лицензии
        (состояние должно быть <code>valid</code> или <code>grandfathered</code>).
    </div>
@endif

@if($allowUnsigned)
    <div class="alert alert-error">
        <strong>Developer mode включён.</strong> Стороннее расширение — это обычный PHP-код с полным доступом
        к данным и файлам. Устанавливайте только проверенные расширения; каждое изменение пишется в аудит.
    </div>
@endif

<p style="color: #6B778C; font-size: 0.875rem; margin-bottom: 1rem;">
    Список и разрешения приходят из ядра по лицензии. Выключенное расширение исчезает из API и меню,
    но его данные сохраняются. Манифест и правила описаны в <code>docs/11-extensions.md</code>.
</p>

@if($canInstall)
    <div class="form-card" style="max-width: 100%; margin-bottom: 2rem;">
        <h2>Установить расширение</h2>
        <p style="color: #6B778C; font-size: 0.875rem; margin-bottom: 1rem;">
            Архив <code>.zip</code> с <code>extension.json</code> в корне.
            @if(! $allowUnsigned)
                Сторонние расширения запрещены: включите <code>EXTENSIONS_ALLOW_UNSIGNED=true</code>
                (developer mode) — это доверие уровня «админ сервера».
            @endif
            Подписанный манифест проверяется публичным ключом вендора.
        </p>
        <form method="POST" action="{{ route('admin.extensions.install') }}" enctype="multipart/form-data">
            @csrf
            <div style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-end;">
                <div class="form-group" style="margin-bottom: 0;">
                    <label>Архив расширения</label>
                    <input type="file" name="archive" accept=".zip,application/zip" required>
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label style="display: flex; align-items: center; gap: 0.4rem;">
                        <input type="checkbox" name="force" value="1">
                        Переустановить существующий
                    </label>
                </div>
                <button type="submit" class="btn btn-primary" @disabled(! $allowUnsigned)>Загрузить</button>
            </div>
        </form>
    </div>
@endif

<div class="data-table">
    <table>
        <thead>
            <tr>
                <th>Расширение</th>
                <th>Версия</th>
                <th>Tier</th>
                <th>Entitlement</th>
                <th>Разрешён</th>
                <th>Состояние</th>
                <th>Действия</th>
            </tr>
        </thead>
        <tbody>
            @forelse($extensions as $extension)
                <tr>
                    <td>
                        <strong>{{ $extension['name'] }}</strong>
                        <div style="font-family: monospace; font-size: 0.75rem; color: #6B778C;">{{ $extension['id'] }}</div>
                    </td>
                    <td>{{ $extension['version'] }}</td>
                    <td>
                        <span class="badge {{ $extension['tier'] === 'vendor' ? 'badge-info' : 'badge-danger' }}">
                            {{ $extension['tier'] }}
                        </span>
                    </td>
                    <td>{{ $extension['entitlement'] ?? '—' }}</td>
                    <td>
                        @if($extension['allowed'])
                            <span class="badge badge-success">да</span>
                        @else
                            <span class="badge badge-danger">нет</span>
                            @if($extension['reason'] !== '')
                                <div style="font-size: 0.75rem; color: #6B778C; margin-top: 0.25rem;">{{ $extension['reason'] }}</div>
                            @endif
                        @endif
                    </td>
                    <td>
                        @if($extension['enabled'])
                            <span class="badge badge-success">включён</span>
                        @else
                            <span class="badge badge-danger">выключен</span>
                        @endif
                    </td>
                    <td>
                        <form method="POST" action="{{ route('admin.extensions.toggle', $extension['id']) }}" style="display: inline;">
                            @csrf
                            <input type="hidden" name="enabled" value="{{ $extension['enabled'] ? '0' : '1' }}">
                            <button type="submit" class="btn btn-secondary btn-sm" @disabled(! $extension['allowed'] && ! $extension['enabled'])>
                                {{ $extension['enabled'] ? 'Выключить' : 'Включить' }}
                            </button>
                        </form>

                        @if($extension['tier'] !== 'vendor')
                            <form method="POST" action="{{ route('admin.extensions.uninstall', $extension['id']) }}" style="display: inline;"
                                  onsubmit="return confirm('Удалить код расширения {{ $extension['id'] }}? Данные сохранятся.');">
                                @csrf
                                <button type="submit" class="btn btn-danger btn-sm">Удалить</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" style="text-align: center; padding: 2rem; color: #6B778C;">
                        Расширения не найдены: проверьте каталог <code>backend/extensions</code> и манифесты.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
