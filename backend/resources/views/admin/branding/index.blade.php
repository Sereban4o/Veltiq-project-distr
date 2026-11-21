@extends('admin.layout')

@section('title', 'Брендинг и тема')

@section('content')
<h1 style="margin-bottom: 1.5rem; color: #172B4D;">🎨 Брендинг и тема</h1>

<div class="form-card" style="max-width: 900px; margin-bottom: 1.5rem;">
    <h2 style="font-size: 1.05rem; margin-bottom: 0.75rem;">Брендинг инстанса</h2>
    <p style="color: #6B778C; font-size: 0.875rem; margin: 0;">
        Настройки применяются к клиентам без пересборки. Несколько пресетов не поддерживаются —
        используется одна текущая конфигурация.
    </p>
</div>

@if($errors->any())
    <div class="alert alert-error">
        <ul style="margin: 0; padding-left: 1.25rem;">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="form-card" style="max-width: 900px;">
    <h2>Внешний вид</h2>
    <p style="color: #6B778C; font-size: 0.875rem; margin-bottom: 1.25rem;">
        Применяется на веб-клиенте без пересборки (runtime). Недопустимые значения отбрасываются при сохранении:
        цвета — только hex, URL — относительные или http(s), <code>customCss</code> санитизируется.
    </p>

    <form method="POST" action="{{ route('admin.branding.update') }}">
        @csrf
        @method('PUT')

        <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
            <div class="form-group" style="min-width: 220px; flex: 1;">
                <label>Название приложения</label>
                <input type="text" name="appName" value="{{ old('appName', $config['appName']) }}">
            </div>
            <div class="form-group" style="min-width: 160px;">
                <label>Идентификатор бренда</label>
                <input type="text" name="brand" value="{{ old('brand', $config['brand']) }}">
            </div>
            <div class="form-group" style="min-width: 140px;">
                <label>Тема по умолчанию</label>
                <select name="mode">
                    @foreach(['system' => 'Как в системе', 'light' => 'Светлая', 'dark' => 'Тёмная'] as $value => $label)
                        <option value="{{ $value }}" @selected(old('mode', $config['theme']['palette']['mode']) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
            <div class="form-group" style="min-width: 240px; flex: 1;">
                <label>Логотип (URL или /путь)</label>
                <input type="text" name="logo" value="{{ old('logo', $config['logo']) }}" placeholder="/veltiq-icon.svg">
            </div>
            <div class="form-group" style="min-width: 240px; flex: 1;">
                <label>Фавикон (URL или /путь)</label>
                <input type="text" name="favicon" value="{{ old('favicon', $config['favicon']) }}" placeholder="/favicon.svg">
            </div>
        </div>

        <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
            <div class="form-group" style="min-width: 160px;">
                <label>Основной цвет</label>
                <input type="color" name="primary" value="{{ old('primary', $config['theme']['palette']['primary']) }}" style="height: 42px; padding: 0.25rem;">
            </div>
            <div class="form-group" style="min-width: 160px;">
                <label>Дополнительный цвет</label>
                <input type="color" name="secondary" value="{{ old('secondary', $config['theme']['palette']['secondary']) }}" style="height: 42px; padding: 0.25rem;">
            </div>
            <div class="form-group" style="min-width: 120px;">
                <label>Радиус, px</label>
                <input type="number" name="borderRadius" min="0" max="32" value="{{ old('borderRadius', $config['theme']['shape']['borderRadius']) }}">
            </div>
            <div class="form-group" style="min-width: 260px; flex: 1;">
                <label>Шрифт</label>
                <input type="text" name="fontFamily" value="{{ old('fontFamily', $config['theme']['typography']['fontFamily']) }}" placeholder="Inter, Helvetica, Arial, sans-serif">
            </div>
        </div>

        <div class="form-group">
            <label>Custom CSS (санитизируется)</label>
            <textarea name="customCss" rows="8" style="font-family: monospace; font-size: 0.8125rem;" placeholder=":root { --brand-accent: #…; }">{{ old('customCss', $config['customCss']) }}</textarea>
        </div>

        <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
            <div class="form-group" style="min-width: 240px; flex: 1;">
                <label>Порядок меню (ключи через запятую)</label>
                <input type="text" name="menu_order" value="{{ old('menu_order', implode(', ', $config['menu']['order'])) }}" placeholder="board, issues, knowledgebase, chat">
            </div>
            <div class="form-group" style="min-width: 240px; flex: 1;">
                <label>Скрыть пункты меню (ключи через запятую)</label>
                <input type="text" name="menu_hidden" value="{{ old('menu_hidden', implode(', ', $config['menu']['hidden'])) }}" placeholder="downloads, timesheet">
            </div>
        </div>
        <p style="color: #6B778C; font-size: 0.75rem; margin-bottom: 1rem;">
            Доступные ключи: board, timeline, automations, timesheet, calendar, crm, mail, files, assistant, downloads, knowledgebase.
        </p>

        @php
            $labelsText = collect($config['i18n']['labels'] ?? [])
                ->map(fn ($value, $key) => $key . ' = ' . $value)
                ->implode("\n");
        @endphp
        <div class="form-group">
            <label>Метки меню (ключ = текст, по строке)</label>
            <textarea name="labels" rows="4" style="font-family: monospace; font-size: 0.8125rem;" placeholder="board = Доска задач&#10;knowledgebase = База знаний">{{ old('labels', $labelsText) }}</textarea>
        </div>

        @php
            $layout = is_array($config['layout'] ?? null) ? $config['layout'] : [];
            $layoutSidebar = is_array($layout['sidebar'] ?? null) ? $layout['sidebar'] : [];
            $layoutShell = old('layout_shell', $layout['shell'] ?? 'sidebar-left');
            $sidebarWidth = old('sidebar_width', $layoutSidebar['width'] ?? 260);
            $sidebarCollapsed = old('sidebar_collapsed', $layoutSidebar['collapsed'] ?? 56);
            $sidebarDefaultCollapsed = (bool) old('sidebar_default_collapsed', $layoutSidebar['defaultCollapsed'] ?? false);
            $regionsJson = old(
                'layout_regions',
                json_encode($layout['regions'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
            $customHtmlJson = old(
                'layout_html',
                json_encode($layout['customHtml'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
            $customIframeJson = old(
                'layout_iframe',
                json_encode($layout['customIframe'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        @endphp

        <h2 style="font-size: 1.05rem; margin: 1.5rem 0 0.75rem;">Раскладка</h2>
        <p style="color: #6B778C; font-size: 0.875rem; margin-bottom: 1rem;">
            Где стоит сайдбар и какие блоки в каких регионах. Регионы — JSON с ключами:
            sidebar.top, sidebar.main, sidebar.bottom, appbar.left, appbar.right, content.before, content.after.
        </p>

        <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
            <div class="form-group" style="min-width: 200px;">
                <label>Расположение сайдбара</label>
                <select name="layout_shell">
                    @foreach(['sidebar-left' => 'Слева', 'sidebar-right' => 'Справа', 'sidebar-top' => 'Сверху', 'sidebar-bottom' => 'Снизу'] as $value => $label)
                        <option value="{{ $value }}" @selected($layoutShell === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-group" style="min-width: 140px;">
                <label>Ширина, px</label>
                <input type="number" name="sidebar_width" min="180" max="400" value="{{ $sidebarWidth }}">
            </div>
            <div class="form-group" style="min-width: 140px;">
                <label>Свёрнутый, px</label>
                <input type="number" name="sidebar_collapsed" min="40" max="120" value="{{ $sidebarCollapsed }}">
            </div>
            <label style="display: inline-flex; align-items: center; gap: 0.4rem; color: #6B778C; font-size: 0.875rem;">
                <input type="hidden" name="sidebar_default_collapsed" value="0">
                <input type="checkbox" name="sidebar_default_collapsed" value="1" @checked($sidebarDefaultCollapsed)>
                Свёрнут по умолчанию
            </label>
        </div>

        <div class="form-group">
            <label>Регионы (JSON)</label>
            <textarea name="layout_regions" rows="10" style="font-family: monospace; font-size: 0.8125rem;">{{ $regionsJson }}</textarea>
        </div>

        <div class="form-group">
            <label>HTML-слоты (JSON: регион → HTML)</label>
            <textarea name="layout_html" rows="6" style="font-family: monospace; font-size: 0.8125rem;" placeholder='{"content.after": "<p>© Acme</p>"}'>{{ $customHtmlJson }}</textarea>
            <p style="color: #6B778C; font-size: 0.75rem; margin-top: 0.35rem;">Статичная разметка; скрипты и обработчики вырезаются на сервере.</p>
        </div>

        <div class="form-group">
            <label>HTML-шаблоны в песочнице (JSON: регион → HTML-документ)</label>
            <textarea name="layout_iframe" rows="6" style="font-family: monospace; font-size: 0.8125rem;" placeholder='{"content.after": "<!doctype html><p>Полный HTML/CSS/JS</p>"}'>{{ $customIframeJson }}</textarea>
            <p style="color: #6B778C; font-size: 0.75rem; margin-top: 0.35rem;">Рендерится в &lt;iframe sandbox="allow-scripts"&gt; без доступа к приложению.</p>
        </div>

        <div style="display: flex; gap: 1rem;">
            <button type="submit" class="btn btn-primary">💾 Сохранить</button>
        </div>
    </form>
</div>

<div class="form-card" style="max-width: 900px; margin-top: 1.5rem;">
    <h2>Предпросмотр конфигурации</h2>
    <p style="color: #6B778C; font-size: 0.875rem; margin-bottom: 1rem;">
        Публичный эндпоинт: <code>GET /api/v1/branding</code>.
    </p>
    <pre style="background: #FAFBFC; border: 1px solid #DFE1E6; border-radius: 8px; padding: 1rem; overflow: auto; font-size: 0.8125rem;">{{ json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
</div>
@endsection
