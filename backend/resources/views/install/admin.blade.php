@extends('install.layout', ['step' => 'admin'])

@section('content')
    <h1>Администратор сайта</h1>
    <p class="hint" style="margin-bottom: 1.25rem;">
        Будет создана схема базы данных, заполнены справочники (статусы, приоритеты, роли, права)
        и создана учётная запись администратора. Демонстрационных пользователей не будет.
    </p>

    <div class="card">
        <form method="POST" action="{{ route('install.finish') }}">
            @csrf

            <div class="field">
                <label for="name">Имя</label>
                <input id="name" name="name" value="{{ old('name') }}" required autocomplete="name">
            </div>

            <div class="field">
                <label for="email">E-mail (логин)</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="email">
            </div>

            <div class="row">
                <div class="field">
                    <label for="password">Пароль</label>
                    <input id="password" name="password" type="password" minlength="8" required autocomplete="new-password">
                    <div class="hint">Не короче 8 символов.</div>
                </div>
                <div class="field">
                    <label for="password_confirmation">Пароль ещё раз</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">
                </div>
            </div>

            <div class="actions">
                <button type="submit">Установить</button>
                <a href="{{ route('install.database') }}" class="hint">Назад к базе</a>
            </div>
        </form>
    </div>
@endsection
