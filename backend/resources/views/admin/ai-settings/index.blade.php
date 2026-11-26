@extends('admin.layout')

@section('title', 'ИИ-ассистент')

@section('content')
<h1 style="margin-bottom: 1.5rem;">🤖 ИИ-ассистент (Ollama)</h1>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-error">{{ session('error') }}</div>@endif
@if($errors->any())
    <div class="alert alert-error">
        <ul style="margin:0;padding-left:1.25rem;">
            @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
        </ul>
    </div>
@endif

<div class="form-card" style="max-width: 100%; margin-bottom: 2rem;">
    <h2>Настройки</h2>
    <p class="muted" style="margin-top:0;">
        Значения из этой формы хранятся в БД и перекрывают env-дефолты
        (<code>AI_ENABLED</code>, <code>OLLAMA_URL</code>, <code>AI_MODEL</code>, …).
        Пустое поле — используется env-значение.
    </p>

    <form method="POST" action="{{ route('admin.ai-settings.update') }}">
        @csrf

        <label style="display:flex;align-items:center;gap:.5rem;color:var(--text);margin-top:.5rem;">
            <input type="checkbox" name="ai_enabled" value="1" style="width:auto;" @checked((string) $settings['ai_enabled'] === '1')>
            Включить ИИ-ассистента
        </label>

        <div class="row" style="gap:1rem;margin-top:.5rem;">
            <div style="flex:2;min-width:240px;">
                <label>Адрес Ollama</label>
                <input name="ai_url" value="{{ $settings['ai_url'] }}" maxlength="255" placeholder="http://ollama:11434">
                <div class="muted">env: <code>{{ $envUrl !== '' ? $envUrl : '—' }}</code></div>
            </div>
            <div style="flex:1;min-width:180px;">
                <label>Модель</label>
                <input name="ai_model" value="{{ $settings['ai_model'] }}" maxlength="100" placeholder="llama3.1">
                <div class="muted">env: <code>{{ $envModel !== '' ? $envModel : '—' }}</code></div>
            </div>
        </div>

        <div class="row" style="gap:1rem;">
            <div style="flex:1;min-width:150px;">
                <label>Таймаут, сек</label>
                <input type="number" name="ai_timeout" value="{{ $settings['ai_timeout'] }}" min="1" max="600">
            </div>
            <div style="flex:1;min-width:150px;">
                <label>Источников в контексте</label>
                <input type="number" name="ai_max_sources" value="{{ $settings['ai_max_sources'] }}" min="1" max="20">
                <div class="muted">Общий лимит по всем разделам (документация, задачи, файлы, почта, календарь, CRM, табель, сотрудники); рекомендуется 8–12</div>
            </div>
            <div style="flex:1;min-width:180px;">
                <label>Лимит контекста, символов</label>
                <input type="number" name="ai_max_context_chars" value="{{ $settings['ai_max_context_chars'] }}" min="500" max="50000">
                <div class="muted">Делится между системным промптом, историей и найденными источниками; чат в контекст не попадает</div>
            </div>
        </div>

        <div class="row" style="gap:1rem;">
            <div style="flex:1;min-width:200px;">
                <label>Сообщений истории в промпте</label>
                <input type="number" name="ai_history_messages" value="{{ $settings['ai_history_messages'] }}" min="0" max="50">
                <div class="muted"><code>0</code> — не передавать предыдущие сообщения диалога</div>
            </div>
            <div style="flex:1;min-width:200px;">
                <label>Срок хранения диалогов, дней</label>
                <input type="number" name="ai_retention_days" value="{{ $settings['ai_retention_days'] }}" min="1" max="3650">
                <div class="muted">Команда <code>ai:prune-conversations</code> удаляет диалоги без активности дольше этого срока</div>
            </div>
        </div>

        <div style="margin-top:1.5rem;padding-top:1rem;border-top:1px solid var(--border,#ddd);">
            <h3 style="margin:0 0 .25rem;">Интернет-поиск (SearXNG)</h3>
            <p class="muted" style="margin:0 0 .75rem;">
                Если во внутренней базе ничего не нашлось, ассистент может спросить SearXNG.
                Наружу уходит <b>только текст вопроса</b>: названия страниц и задач, данные CRM,
                имена сотрудников и внутренние сниппеты не отправляются.
                При выключенном флаге исходящих запросов нет вообще.
            </p>

            <label style="display:flex;align-items:center;gap:.5rem;color:var(--text);">
                <input type="checkbox" name="ai_web_enabled" value="1" style="width:auto;" @checked((string) $settings['ai_web_enabled'] === '1')>
                Разрешить поиск в интернете, если в базе ничего не нашлось
            </label>

            <div class="row" style="gap:1rem;margin-top:.5rem;">
                <div style="flex:2;min-width:240px;">
                    <label>Адрес SearXNG</label>
                    <input name="ai_web_url" value="{{ $settings['ai_web_url'] }}" maxlength="255" placeholder="http://searxng:8080">
                    <div class="muted">env: <code>{{ $envWebUrl !== '' ? $envWebUrl : '—' }}</code> · нужен <code>search.formats: [html, json]</code> в settings.yml</div>
                </div>
                <div style="flex:1;min-width:140px;">
                    <label>Таймаут, сек</label>
                    <input type="number" name="ai_web_timeout" value="{{ $settings['ai_web_timeout'] }}" min="1" max="30">
                </div>
                <div style="flex:1;min-width:160px;">
                    <label>Внешних ссылок в контексте</label>
                    <input type="number" name="ai_web_results" value="{{ $settings['ai_web_results'] }}" min="1" max="5">
                </div>
            </div>
        </div>

        <div style="margin-top:1.25rem;">
            <button class="btn btn-primary" type="submit">Сохранить</button>
        </div>
    </form>
</div>

<div class="form-card" style="max-width: 100%;">
    <h2>Проверка подключения</h2>
    <p class="muted" style="margin-top:0;">Запрашивает <code>/api/tags</code> у Ollama и проверяет наличие выбранной модели.</p>
    <form method="POST" action="{{ route('admin.ai-settings.test') }}">
        @csrf
        <button class="btn btn-secondary" type="submit">Проверить подключение</button>
    </form>

    <h3 style="margin:1.5rem 0 .25rem;">Проверка SearXNG</h3>
    <p class="muted" style="margin-top:0;">
        Запрашивает <code>/search?q=tasksbase&amp;format=json</code>. Если приходит 403 — включите формат JSON
        в <code>settings.yml</code> самого SearXNG.
    </p>
    <form method="POST" action="{{ route('admin.ai-settings.test-web') }}">
        @csrf
        <button class="btn btn-secondary" type="submit">Проверить SearXNG</button>
    </form>
</div>
@endsection
