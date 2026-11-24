<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Установка — {{ config('app.name', 'Veltiq') }}</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Inter, system-ui, -apple-system, "Segoe UI", sans-serif; background: #f4f5f7; color: #172b4d; }
        .shell { max-width: 760px; margin: 0 auto; padding: 3rem 1.25rem 4rem; }
        .brand { display: flex; align-items: center; gap: .75rem; margin-bottom: 1.5rem; }
        .logo { width: 42px; height: 42px; border-radius: 10px; background: #0052cc; color: #fff; display: grid; place-items: center; font-weight: 700; }
        h1 { font-size: 1.6rem; margin: 0 0 .5rem; }
        .steps { display: flex; gap: .5rem; margin: 1rem 0 1.5rem; font-size: .8125rem; color: #6b778c; flex-wrap: wrap; }
        .steps span { padding: .25rem .6rem; border-radius: 999px; background: #e9edf2; }
        .steps span.active { background: #0052cc; color: #fff; }
        .card { background: #fff; border: 1px solid #dfe1e6; border-radius: 12px; padding: 1.5rem; }
        .row { display: flex; gap: 1rem; flex-wrap: wrap; }
        .field { flex: 1 1 220px; margin-bottom: 1rem; }
        label { display: block; font-weight: 600; font-size: .875rem; margin-bottom: .35rem; }
        input, select { width: 100%; padding: .6rem .7rem; border: 1px solid #c1c7d0; border-radius: 8px; font: inherit; background: #fff; }
        input:focus, select:focus { outline: 2px solid #4c9aff; border-color: #0052cc; }
        .hint { font-size: .8125rem; color: #6b778c; margin-top: .25rem; }
        .check { display: flex; align-items: center; gap: .5rem; font-weight: 500; }
        .check input { width: auto; }
        button { background: #0052cc; color: #fff; border: 0; border-radius: 8px; padding: .7rem 1.4rem; font: inherit; font-weight: 600; cursor: pointer; }
        button.secondary { background: #fff; color: #172b4d; border: 1px solid #c1c7d0; }
        button:disabled { opacity: .5; cursor: not-allowed; }
        .actions { display: flex; gap: .75rem; align-items: center; margin-top: 1rem; flex-wrap: wrap; }
        .ok { color: #00875a; font-weight: 600; }
        .bad { color: #de350b; font-weight: 600; }
        .alert { border-radius: 8px; padding: .75rem 1rem; margin-bottom: 1rem; font-size: .875rem; }
        .alert-error { background: #ffebe6; color: #bf2600; }
        .alert-success { background: #e3fcef; color: #006644; }
        ul.requirements { list-style: none; padding: 0; margin: 0; }
        ul.requirements li { display: flex; justify-content: space-between; gap: 1rem; padding: .5rem 0; border-bottom: 1px solid #f4f5f7; }
        ul.requirements li:last-child { border-bottom: 0; }
        code { background: #f4f5f7; border: 1px solid #dfe1e6; border-radius: 4px; padding: .1rem .3rem; }
    </style>
</head>
<body>
<div class="shell">
    <div class="brand">
        <div class="logo">V</div>
        <div>
            <strong style="display:block;">{{ config('app.name', 'Veltiq') }}</strong>
            <span class="hint">Установка</span>
        </div>
    </div>

    <div class="steps">
        <span class="{{ ($step ?? '') === 'requirements' ? 'active' : '' }}">1. Проверка</span>
        <span class="{{ ($step ?? '') === 'database' ? 'active' : '' }}">2. База данных</span>
        <span class="{{ ($step ?? '') === 'admin' ? 'active' : '' }}">3. Администратор</span>
    </div>

    @if ($errors->any())
        <div class="alert alert-error">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @yield('content')
</div>
</body>
</html>
