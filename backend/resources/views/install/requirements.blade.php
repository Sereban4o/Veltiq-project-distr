@extends('install.layout', ['step' => 'requirements'])

@section('content')
    <h1>Добро пожаловать</h1>
    <p class="hint" style="margin-bottom: 1.25rem;">
        Инсталлятор проверит окружение, создаст базу данных и учётную запись администратора.
        После завершения вход в панель — по адресу <code>/admin/login</code>.
    </p>

    <div class="card">
        <h2 style="font-size: 1.1rem; margin: 0 0 .75rem;">Проверка окружения</h2>
        <ul class="requirements">
            @foreach ($requirements as $requirement)
                <li>
                    <span>
                        {{ $requirement['label'] }}
                        <span class="hint" style="display:block;">{{ $requirement['hint'] }}</span>
                    </span>
                    <span class="{{ $requirement['ok'] ? 'ok' : 'bad' }}">{{ $requirement['ok'] ? 'OK' : 'Нет' }}</span>
                </li>
            @endforeach
        </ul>

        <div class="actions">
            @php($ready = ! in_array(false, array_column($requirements, 'ok'), true))
            <a href="{{ route('install.database') }}">
                <button type="button" @disabled(! $ready)>Продолжить</button>
            </a>
            @if (! $ready)
                <span class="hint">Исправьте пункты с «Нет», затем обновите страницу.</span>
            @endif
        </div>
    </div>

    <p class="hint" style="margin-top: 1rem;">
        Поддерживаемые СУБД: {{ $drivers === [] ? 'нет доступных драйверов PDO' : implode(', ', $drivers) }}.
    </p>
@endsection
