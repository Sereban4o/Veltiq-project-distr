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
                <div class="muted">Общий лимит по всем разделам; рекомендуется 8–12</div>
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
            <h3 style="margin:0 0 .25rem;">Разделы, которые ассистент может читать</h3>
            <p class="muted" style="margin:0 0 .75rem;">
                Снятые галочки полностью убирают раздел из поиска: ассистент не увидит его ни по запросу,
                ни по явному указанию типа. Права доступа внутри разделов всё равно проверяются —
                пользователь не получит то, что не может открыть в интерфейсе.
                <b>Ничего не выбрано — доступны все разделы.</b>
                Личные сообщения чата недоступны всегда.
            </p>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.35rem .75rem;">
                @foreach($sections as $value => $label)
                    <label style="display:flex;align-items:center;gap:.5rem;color:var(--text);">
                        <input type="checkbox" name="ai_sections[]" value="{{ $value }}" style="width:auto;"
                               @checked(in_array($value, $settings['ai_sections'], true))>
                        {{ $label }}
                    </label>
                @endforeach
            </div>
            <div class="muted" style="margin-top:.5rem;">env: <code>{{ $envSections !== '' ? $envSections : '—' }}</code></div>
        </div>

        <div style="margin-top:1.5rem;padding-top:1rem;border-top:1px solid var(--border,#ddd);">
            <h3 style="margin:0 0 .25rem;">Кому доступен ассистент</h3>
            <p class="muted" style="margin:0 0 .75rem;">
                Роли, которые видят ассистента и могут задавать вопросы. Остальным API отвечает
                403, а точка входа скрыта. <b>Ничего не выбрано — доступен всем авторизованным.</b>
            </p>

            @if($roles === [])
                <div class="muted">Роли не найдены — ограничение по ролям недоступно.</div>
            @else
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.35rem .75rem;">
                    @foreach($roles as $role)
                        <label style="display:flex;align-items:center;gap:.5rem;color:var(--text);">
                            <input type="checkbox" name="ai_roles[]" value="{{ $role }}" style="width:auto;"
                                   @checked(in_array($role, $settings['ai_roles'], true))>
                            {{ $role }}
                        </label>
                    @endforeach
                </div>
            @endif
            <div class="muted" style="margin-top:.5rem;">env: <code>{{ $envRoles !== '' ? $envRoles : '—' }}</code></div>
        </div>

        <div style="margin-top:1.5rem;padding-top:1rem;border-top:1px solid var(--border,#ddd);">
            <h3 style="margin:0 0 .25rem;">Вывод ответов</h3>
            <p class="muted" style="margin:0 0 .75rem;">
                Список источников под ответом помогает проверить, откуда взяты факты. Если он не нужен
                пользователям, снимите флажок: сноски вида <code>[1]</code> в тексте ответа останутся,
                скроется только блок «Источники».
            </p>

            <label style="display:flex;align-items:center;gap:.5rem;color:var(--text);">
                <input type="checkbox" name="ai_show_sources" value="1" style="width:auto;" @checked((string) $settings['ai_show_sources'] === '1')>
                Показывать источники в диалоге
            </label>
        </div>

        <div style="margin-top:1.5rem;padding-top:1rem;border-top:1px solid var(--border,#ddd);">
            <h3 style="margin:0 0 .25rem;">Выход в интернет</h3>
            <p class="muted" style="margin:0 0 .75rem;">
                Срабатывает только если во внутренней базе ничего не нашлось. При выключенном флажке
                исходящих запросов нет вообще.
            </p>

            <label style="display:flex;align-items:center;gap:.5rem;color:var(--text);">
                <input type="checkbox" name="ai_web_enabled" value="1" style="width:auto;" @checked((string) $settings['ai_web_enabled'] === '1')>
                Разрешить выход в интернет
            </label>

            <div class="row" style="gap:1rem;margin-top:.75rem;">
                <div style="flex:1;min-width:260px;">
                    <label>Режим</label>
                    <select name="ai_web_provider">
                        <option value="fetch" @selected($settings['ai_web_provider'] === 'fetch')>Чтение ссылок из вопроса (без поисковика)</option>
                        <option value="searxng" @selected($settings['ai_web_provider'] === 'searxng')>Поиск через SearXNG</option>
                        <option value="both" @selected($settings['ai_web_provider'] === 'both')>Сначала ссылки, затем поиск SearXNG</option>
                    </select>
                    <div class="muted">
                        <b>Чтение</b> скачивает только адреса, указанные в тексте вопроса, и только с разрешённых доменов;
                        наружу уходит сам запрос страницы. <b>Поиск</b> отправляет в SearXNG только текст вопроса.
                        В режиме «оба» ссылка из вопроса имеет приоритет.
                    </div>
                </div>
                <div style="flex:1;min-width:240px;">
                    <label>Адрес SearXNG</label>
                    <input name="ai_web_url" value="{{ $settings['ai_web_url'] }}" maxlength="255" placeholder="http://searxng:8080">
                    <div class="muted">
                        env: <code>{{ $envWebUrl !== '' ? $envWebUrl : '—' }}</code> · нужен
                        <code>search.formats: [html, json]</code> в settings.yml самого SearXNG
                    </div>
                </div>
            </div>

            <div class="row" style="gap:1rem;margin-top:.5rem;">
                <div style="flex:2;min-width:260px;">
                    <label>Разрешённые домены (режим чтения)</label>
                    <textarea name="ai_web_domains" rows="4" maxlength="2000" placeholder="example.com&#10;docs.example.com">{{ $settings['ai_web_domains'] }}</textarea>
                    <div class="muted">
                        По одному в строке. Домен разрешает и поддомены; <code>*.example.com</code> — только поддомены.
                        Приватные и служебные адреса (localhost, 10.x, 192.168.x, 172.16–31.x) блокируются даже при добавлении в список.
                        env: <code>{{ $envDomains !== '' ? $envDomains : '—' }}</code>
                    </div>
                </div>
                <div style="flex:1;min-width:150px;">
                    <label>Таймаут, сек</label>
                    <input type="number" name="ai_web_timeout" value="{{ $settings['ai_web_timeout'] }}" min="1" max="30">
                    <label style="margin-top:.75rem;">Источников из интернета</label>
                    <input type="number" name="ai_web_results" value="{{ $settings['ai_web_results'] }}" min="1" max="5">
                    <label style="margin-top:.75rem;">Символов со страницы</label>
                    <input type="number" name="ai_web_max_chars" value="{{ $settings['ai_web_max_chars'] }}" min="500" max="20000">
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

    <h3 style="margin:1.5rem 0 .25rem;">Проверка выхода в интернет</h3>
    <p class="muted" style="margin-top:0;">
        Проверяет выбранный режим: в режиме чтения скачивает главную первого домена из списка,
        в режиме поиска запрашивает SearXNG (<code>/search?q=tasksbase&amp;format=json</code>),
        в режиме «оба» — оба механизма. Настройки должны быть сохранены заранее.
    </p>
    <form method="POST" action="{{ route('admin.ai-settings.test-web') }}">
        @csrf
        <button class="btn btn-secondary" type="submit">Проверить выход в интернет</button>
    </form>
</div>
@endsection
