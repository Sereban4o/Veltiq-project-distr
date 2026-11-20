@php
    $theme = is_array($config['theme'] ?? null) ? $config['theme'] : [];
    $palette = is_array($theme['palette'] ?? null) ? $theme['palette'] : [];
    $typography = is_array($theme['typography'] ?? null) ? $theme['typography'] : [];
    $shape = is_array($theme['shape'] ?? null) ? $theme['shape'] : [];
    $menu = is_array($config['menu'] ?? null) ? $config['menu'] : [];
    $i18n = is_array($config['i18n'] ?? null) ? $config['i18n'] : [];

    $primary = old('primary', $palette['primary'] ?? '#0052CC');
    $secondary = old('secondary', $palette['secondary'] ?? '#7C3AED');
    $radius = old('borderRadius', $shape['borderRadius'] ?? 12);
    $fontFamily = old('fontFamily', $typography['fontFamily'] ?? 'Inter, Helvetica, Arial, sans-serif');
    $mode = old('mode', $palette['mode'] ?? 'system');
    $layout = old('layout', $theme['layout'] ?? 'comfortable');
    $appName = old('appName', $i18n['appTitle'] ?? $config['appName'] ?? 'Veltiq project');
    $brand = old('brand', $config['brand'] ?? 'veltiq');

    $logoValue = old('logo_url', $skin?->logo_path ?? $config['logo'] ?? '');
    $faviconValue = old('favicon_url', $skin?->favicon_path ?? $config['favicon'] ?? '');

    $labelsText = collect($i18n['labels'] ?? [])
        ->map(fn ($value, $key) => $key . ' = ' . $value)
        ->implode("\n");
@endphp

@once
    @push('styles')
        <style>
            .skin-editor { display: grid; grid-template-columns: minmax(0, 1fr) 460px; gap: 1.5rem; align-items: start; }
            .skin-editor-main { display: flex; flex-direction: column; gap: 1.25rem; min-width: 0; }
            .skin-editor-main .form-card { max-width: 100%; }
            .skin-editor-aside { position: sticky; top: 84px; }
            .skin-editor-aside .form-card { max-width: 100%; padding: 1rem; }

            .skin-section-title { margin-bottom: 1rem; color: var(--text); font-size: 1.05rem; }
            .skin-hint { color: var(--muted); font-size: 0.8125rem; margin-bottom: 1rem; }
            .skin-row { display: flex; gap: 1rem; flex-wrap: wrap; }
            .skin-row > .form-group { flex: 1; min-width: 180px; }

            .skin-color { display: flex; gap: 0.5rem; align-items: center; }
            .skin-color input[type="color"] { width: 52px; height: 42px; padding: 0.2rem; flex: 0 0 auto; }
            .skin-color input[type="text"] { font-family: ui-monospace, Consolas, monospace; }
            .skin-presets { display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 1.25rem; }
            .skin-preset {
                display: inline-flex; align-items: center; gap: 0.4rem;
                padding: 0.35rem 0.6rem; border: 1px solid var(--border); border-radius: 999px;
                background: var(--surface); color: var(--text); cursor: pointer; font-size: 0.8125rem;
            }
            .skin-preset:hover { border-color: var(--primary); }
            .skin-preset i { width: 14px; height: 14px; border-radius: 50%; display: block; }

            .skin-range { display: flex; gap: 0.75rem; align-items: center; }
            .skin-range input[type="range"] { flex: 1; }
            .skin-range input[type="number"] { width: 88px; }

            .skin-upload { border: 1px dashed var(--border); border-radius: 8px; padding: 0.9rem; background: var(--surface-2); }
            .skin-upload-preview {
                display: flex; align-items: center; justify-content: center;
                min-height: 72px; margin-bottom: 0.75rem; padding: 0.5rem;
                background: var(--surface); border: 1px solid var(--border); border-radius: 6px;
            }
            .skin-upload-preview img { max-width: 100%; max-height: 56px; display: block; }
            .skin-upload-preview .skin-upload-empty { color: var(--muted); font-size: 0.8125rem; }

            .skin-preview-head { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; margin-bottom: 0.75rem; }
            .skin-preview-head strong { font-size: 0.95rem; }
            .skin-preview-frame { width: 100%; height: 520px; border: 1px solid var(--border); border-radius: 10px; background: #fff; }
            .skin-preview-frame[hidden] { display: none; }
            .skin-preview-empty { color: var(--muted); font-size: 0.8125rem; padding: 1rem 0; }

            .skin-actions { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; margin-top: 1.5rem; }
            .skin-actions form { margin: 0; }
            .skin-actions label { display: inline-flex; align-items: center; gap: 0.4rem; color: var(--muted); font-size: 0.875rem; }

            @media (max-width: 1200px) {
                .skin-editor { grid-template-columns: minmax(0, 1fr); }
                .skin-editor-aside { position: static; }
            }
        </style>
    @endpush
@endonce

<div class="skin-editor" data-skin-editor>
    <div class="skin-editor-main">
        <div class="form-card">
            <h2 class="skin-section-title">Основное</h2>
            <div class="skin-row">
                <div class="form-group">
                    <label for="skin-name">Название скина</label>
                    <input id="skin-name" type="text" name="name" value="{{ old('name', $skin?->name) }}" required maxlength="120">
                </div>
                <div class="form-group">
                    <label for="skin-brand">Идентификатор бренда</label>
                    <input id="skin-brand" type="text" name="brand" value="{{ $brand }}" maxlength="64" placeholder="acme">
                </div>
            </div>

            <div class="form-group">
                <label for="skin-description">Описание</label>
                <textarea id="skin-description" name="description" rows="2" maxlength="1000" placeholder="Например: корпоративная тема Acme">{{ old('description', $skin?->description) }}</textarea>
            </div>

            <div class="form-group">
                <label for="skin-app-name">Название приложения</label>
                <input id="skin-app-name" type="text" name="appName" value="{{ $appName }}" maxlength="120">
            </div>
        </div>

        <div class="form-card">
            <h2 class="skin-section-title">Палитра и форма</h2>
            <p class="skin-hint">Значения применяются к скомпилированному веб-клиенту без пересборки.</p>

            <div class="skin-presets" data-skin-presets>
                <button type="button" class="skin-preset" data-primary="#0052CC" data-secondary="#7C3AED">
                    <i style="background:#0052CC"></i> Veltiq
                </button>
                <button type="button" class="skin-preset" data-primary="#0F9D58" data-secondary="#0B7A43">
                    <i style="background:#0F9D58"></i> Изумруд
                </button>
                <button type="button" class="skin-preset" data-primary="#E4572E" data-secondary="#B23A1B">
                    <i style="background:#E4572E"></i> Терракота
                </button>
                <button type="button" class="skin-preset" data-primary="#6C4AB6" data-secondary="#4C2F92">
                    <i style="background:#6C4AB6"></i> Фиалка
                </button>
                <button type="button" class="skin-preset" data-primary="#0B7285" data-secondary="#07535F">
                    <i style="background:#0B7285"></i> Океан
                </button>
            </div>

            <div class="skin-row">
                <div class="form-group">
                    <label for="skin-primary-text">Основной цвет</label>
                    <div class="skin-color">
                        <input type="color" value="{{ $primary }}" data-color-picker="primary" aria-label="Основной цвет">
                        <input id="skin-primary-text" type="text" name="primary" value="{{ $primary }}" maxlength="7" data-color-text="primary">
                    </div>
                </div>
                <div class="form-group">
                    <label for="skin-secondary-text">Дополнительный цвет</label>
                    <div class="skin-color">
                        <input type="color" value="{{ $secondary }}" data-color-picker="secondary" aria-label="Дополнительный цвет">
                        <input id="skin-secondary-text" type="text" name="secondary" value="{{ $secondary }}" maxlength="7" data-color-text="secondary">
                    </div>
                </div>
            </div>

            <div class="skin-row">
                <div class="form-group">
                    <label for="skin-radius">Скругление, px</label>
                    <div class="skin-range">
                        <input type="range" min="0" max="32" value="{{ $radius }}" data-range-for="borderRadius" aria-label="Скругление">
                        <input id="skin-radius" type="number" name="borderRadius" min="0" max="32" value="{{ $radius }}">
                    </div>
                </div>
                <div class="form-group">
                    <label for="skin-mode">Тема по умолчанию</label>
                    <select id="skin-mode" name="mode">
                        @foreach(['system' => 'Как в системе', 'light' => 'Светлая', 'dark' => 'Тёмная'] as $value => $label)
                            <option value="{{ $value }}" @selected($mode === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label for="skin-layout">Раскладка</label>
                    <select id="skin-layout" name="layout">
                        @foreach(['comfortable' => 'Просторная', 'compact' => 'Компактная'] as $value => $label)
                            <option value="{{ $value }}" @selected($layout === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label for="skin-font">Шрифт</label>
                <input id="skin-font" type="text" name="fontFamily" value="{{ $fontFamily }}" maxlength="200"
                       placeholder="Inter, Helvetica, Arial, sans-serif">
            </div>
        </div>

        <div class="form-card">
            <h2 class="skin-section-title">Логотип и фавикон</h2>
            <p class="skin-hint">Загрузите файл или укажите URL. SVG-файлы очищаются от скриптов при сохранении.</p>

            <div class="skin-row">
                <div class="form-group" style="min-width: 240px;">
                    <label>Логотип</label>
                    <div class="skin-upload">
                        <div class="skin-upload-preview">
                            <img src="{{ $logoValue }}" alt="Логотип" data-logo-preview @if($logoValue === '') hidden @endif>
                            <span class="skin-upload-empty" data-logo-empty @if($logoValue !== '') hidden @endif>Файл не выбран</span>
                        </div>
                        <input type="file" name="logo_file" accept=".png,.jpg,.jpeg,.webp,.ico,.svg" data-logo-file>
                        <div style="margin-top: 0.5rem;">
                            <input type="text" name="logo_url" value="{{ $logoValue }}" maxlength="2048"
                                   placeholder="/storage/skins/logo.svg или https://…">
                        </div>
                    </div>
                </div>

                <div class="form-group" style="min-width: 240px;">
                    <label>Фавикон</label>
                    <div class="skin-upload">
                        <div class="skin-upload-preview" style="min-height: 56px;">
                            <img src="{{ $faviconValue }}" alt="Фавикон" data-favicon-preview @if($faviconValue === '') hidden @endif>
                            <span class="skin-upload-empty" data-favicon-empty @if($faviconValue !== '') hidden @endif>Файл не выбран</span>
                        </div>
                        <input type="file" name="favicon_file" accept=".png,.jpg,.jpeg,.webp,.ico,.svg" data-favicon-file>
                        <div style="margin-top: 0.5rem;">
                            <input type="text" name="favicon_url" value="{{ $faviconValue }}" maxlength="2048"
                                   placeholder="/storage/skins/favicon.ico">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="form-card">
            <h2 class="skin-section-title">Дополнительно</h2>

            <div class="form-group">
                <label for="skin-custom-css">Custom CSS</label>
                <textarea id="skin-custom-css" name="customCss" rows="6" maxlength="20000"
                          style="font-family: ui-monospace, Consolas, monospace; font-size: 0.8125rem;"
                          placeholder=":root { --brand-accent: #0052CC; }">{{ old('customCss', $config['customCss'] ?? '') }}</textarea>
                <p class="skin-hint" style="margin-top: 0.5rem; margin-bottom: 0;">
                    Санитизируется: теги script/style, <code>@import</code> и <code>javascript:</code> удаляются.
                </p>
            </div>

            <div class="skin-row">
                <div class="form-group">
                    <label for="skin-menu-order">Порядок меню (ключи через запятую)</label>
                    <input id="skin-menu-order" type="text" name="menu_order" maxlength="400"
                           value="{{ old('menu_order', implode(', ', $menu['order'] ?? [])) }}"
                           placeholder="board, issues, knowledgebase, chat">
                </div>
                <div class="form-group">
                    <label for="skin-menu-hidden">Скрыть пункты меню</label>
                    <input id="skin-menu-hidden" type="text" name="menu_hidden" maxlength="400"
                           value="{{ old('menu_hidden', implode(', ', $menu['hidden'] ?? [])) }}"
                           placeholder="downloads, timesheet">
                </div>
            </div>

            <div class="form-group">
                <label for="skin-labels">Метки меню (ключ = текст, по строке)</label>
                <textarea id="skin-labels" name="labels" rows="4" maxlength="4000"
                          style="font-family: ui-monospace, Consolas, monospace; font-size: 0.8125rem;"
                          placeholder="board = Доска задач&#10;knowledgebase = База знаний">{{ old('labels', $labelsText) }}</textarea>
            </div>
        </div>
    </div>

    <aside class="skin-editor-aside">
        <div class="form-card">
            <div class="skin-preview-head">
                <strong>Живое превью</strong>
                <span class="badge badge-info">обновляется автоматически</span>
            </div>
            <iframe class="skin-preview-frame" data-skin-preview title="Превью скина" sandbox="" hidden></iframe>
            <p class="skin-preview-empty" data-skin-preview-empty>Заполните название и палитру — превью появится здесь.</p>
        </div>
    </aside>
</div>

@once
    @push('scripts')
        <script src="{{ asset('js/skin-editor.js') }}"></script>
    @endpush
@endonce
