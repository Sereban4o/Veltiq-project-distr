@extends('install.layout', ['step' => 'database'])

@section('content')
    <h1>База данных</h1>
    <p class="hint" style="margin-bottom: 1.25rem;">
        Выберите сервер баз данных и укажите реквизиты. Если базы ещё нет, включите создание —
        инсталлятор создаст её сам (нужны права на создание).
    </p>

    <div class="card">
        <form method="POST" action="{{ route('install.database.store') }}">
            @csrf

            <div class="row">
                <div class="field">
                    <label for="driver">СУБД</label>
                    <select id="driver" name="driver">
                        @foreach ($drivers as $value => $label)
                            <option value="{{ $value }}" @selected(old('driver', $current['driver']) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="app_url">Адрес сайта</label>
                    <input id="app_url" name="app_url" type="url"
                           value="{{ old('app_url', request()->getSchemeAndHttpHost()) }}" required>
                    <div class="hint">Например, <code>https://tasks.company.ru</code></div>
                </div>
            </div>

            <div class="row">
                <div class="field">
                    <label for="host">Сервер</label>
                    <input id="host" name="host" value="{{ old('host', $current['host']) }}" placeholder="127.0.0.1">
                </div>
                <div class="field">
                    <label for="port">Порт</label>
                    <input id="port" name="port" type="number" min="1" max="65535"
                           value="{{ old('port', $current['port'] ?: ($current['driver'] === 'mysql' ? 3306 : 5432)) }}" placeholder="5432 / 3306">
                </div>
            </div>

            <div class="row">
                <div class="field">
                    <label for="database">Имя базы данных</label>
                    <input id="database" name="database" value="{{ old('database', $current['database']) }}"
                           placeholder="veltiq" required>
                    <div class="hint">Для SQLite укажите полный путь к файлу, например <code>/var/www/veltiq.sqlite</code>.</div>
                </div>
                <div class="field">
                    <label for="username">Пользователь</label>
                    <input id="username" name="username" value="{{ old('username', $current['username']) }}" autocomplete="off">
                </div>
            </div>

            <div class="row">
                <div class="field">
                    <label for="password">Пароль</label>
                    <input id="password" name="password" type="password" value="{{ old('password') }}" autocomplete="new-password">
                </div>
                <div class="field" style="display:flex; align-items:flex-end;">
                    <label class="check">
                        <input type="checkbox" name="create_database" value="1" @checked(old('create_database'))>
                        Создать базу, если её нет
                    </label>
                </div>
            </div>

            <div class="actions">
                <button type="submit">Проверить и продолжить</button>
                <a href="{{ route('install.welcome') }}" class="hint">Назад</a>
            </div>
        </form>
    </div>
@endsection
