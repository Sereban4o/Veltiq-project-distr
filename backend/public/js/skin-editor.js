/**
 * Visual skin editor: keeps the colour/radius/font/logo controls in sync and
 * renders a sandboxed live preview of the compiled client's UI.
 *
 * The preview is only a mock: the server (BrandingService) remains the source
 * of truth and sanitises every value on save.
 */
(function () {
    'use strict';

    var HEX_RE = /^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/;
    var FONT_RE = /^[A-Za-z0-9 ,'"()\-]+$/;

    function escapeHtml(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function safeColor(value, fallback) {
        var text = String(value === null || value === undefined ? '' : value).trim();
        return HEX_RE.test(text) ? text : fallback;
    }

    function safeUrl(value) {
        var text = String(value === null || value === undefined ? '' : value).trim();

        if (text === '') {
            return '';
        }

        if (text.charAt(0) === '/' && text.charAt(1) !== '/') {
            return text;
        }

        return /^https?:\/\/[^\s<>"]+$/i.test(text) ? text : '';
    }

    function safeFont(value, fallback) {
        var text = String(value === null || value === undefined ? '' : value).trim();
        return text !== '' && FONT_RE.test(text) ? text : fallback;
    }

    function safeRadius(value) {
        var number = parseInt(value, 10);
        if (isNaN(number)) {
            return 12;
        }
        return Math.max(0, Math.min(32, number));
    }

    function safeCss(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/<\/?\s*(script|style|iframe|object|embed)\b[^>]*>/gi, '')
            .replace(/@import/gi, '')
            .replace(/expression\s*\(/gi, '')
            .replace(/javascript\s*:/gi, '')
            .replace(/vbscript\s*:/gi, '')
            .replace(/</g, '');
    }

    function previewImageSrc(value) {
        var text = String(value === null || value === undefined ? '' : value).trim();

        if (/^data:image\/(png|jpe?g|gif|webp);/i.test(text)) {
            return text;
        }

        return safeUrl(text);
    }

    function buildPreview(config) {
        var dark = config.mode === 'dark';
        var primary = safeColor(config.primary, '#0052CC');
        var secondary = safeColor(config.secondary, '#7C3AED');
        var radius = safeRadius(config.radius);
        var font = safeFont(config.font, 'Inter, Helvetica, Arial, sans-serif');
        var appName = String(config.appName || 'Veltiq project');
        var logo = previewImageSrc(config.logo);

        var bg = dark ? '#0f1420' : '#F4F5F7';
        var surface = dark ? '#161d2b' : '#ffffff';
        var text = dark ? '#E6EDF6' : '#172B4D';
        var muted = dark ? '#9AA7B8' : '#5E6C84';
        var border = dark ? '#2A3446' : '#D0D5DD';
        var subtle = dark ? 'rgba(255,255,255,0.06)' : '#F1F3F5';

        var brand = logo !== ''
            ? '<img class="brand-logo" src="' + escapeHtml(logo) + '" alt="">'
            : '<span class="brand-dot">' + escapeHtml(appName.charAt(0).toUpperCase()) + '</span>';

        var nav = [
            ['Канбан-доска', true],
            ['Дорожная карта', false],
            ['Календарь', false],
            ['CRM', false],
            ['Почта', false],
            ['Файлы', false],
            ['База знаний', false]
        ].map(function (item) {
            return '<div class="nav-item' + (item[1] ? ' is-active' : '') + '">'
                + '<span class="nav-dot"></span>' + escapeHtml(item[0]) + '</div>';
        }).join('');

        var rows = [
            ['Проверить макеты', 'В работе'],
            ['Обновить онбординг', 'Готово'],
            ['Согласовать бюджет', 'Бэклог']
        ].map(function (row) {
            return '<tr><td>' + escapeHtml(row[0]) + '</td><td><span class="chip">'
                + escapeHtml(row[1]) + '</span></td></tr>';
        }).join('');

        var customCss = safeCss(config.customCss);

        return '<!doctype html><html lang="ru"><head><meta charset="utf-8">'
            + '<style>'
            + ':root{--p:' + primary + ';--s:' + secondary + ';--r:' + radius + 'px;'
            + '--bg:' + bg + ';--surface:' + surface + ';--text:' + text + ';'
            + '--muted:' + muted + ';--border:' + border + ';--subtle:' + subtle + '}'
            + '*{box-sizing:border-box}'
            + 'body{margin:0;background:var(--bg);color:var(--text);font-family:' + font + ';font-size:13px}'
            + '.app{display:flex;min-height:100vh}'
            + '.side{width:196px;flex:0 0 196px;padding:14px 10px;color:#fff;'
            + 'background:linear-gradient(160deg,var(--p) 0%,var(--s) 150%)}'
            + '.brand{display:flex;align-items:center;gap:8px;margin-bottom:18px;font-weight:700;font-size:14px}'
            + '.brand-logo{width:24px;height:24px;object-fit:contain;background:rgba(255,255,255,.14);border-radius:6px}'
            + '.brand-dot{width:24px;height:24px;display:flex;align-items:center;justify-content:center;'
            + 'background:rgba(255,255,255,.2);border-radius:6px;font-size:12px}'
            + '.nav-item{display:flex;align-items:center;gap:8px;padding:7px 9px;margin-bottom:3px;'
            + 'border-radius:calc(var(--r) / 1.6);color:rgba(255,255,255,.82);font-size:12.5px}'
            + '.nav-item.is-active{background:rgba(255,255,255,.18);color:#fff;font-weight:600}'
            + '.nav-dot{width:6px;height:6px;border-radius:50%;background:currentColor;opacity:.65}'
            + '.main{flex:1;min-width:0;display:flex;flex-direction:column}'
            + '.top{display:flex;align-items:center;justify-content:space-between;'
            + 'padding:10px 16px;background:var(--surface);border-bottom:1px solid var(--border)}'
            + '.top-title{font-weight:600;font-size:14px}'
            + '.top-actions{display:flex;align-items:center;gap:8px}'
            + '.top-btn{width:26px;height:26px;border-radius:calc(var(--r) / 2);background:var(--subtle);'
            + 'border:1px solid var(--border)}'
            + '.avatar{width:26px;height:26px;border-radius:50%;background:var(--p)}'
            + '.content{padding:16px;display:flex;flex-direction:column;gap:12px}'
            + '.cards{display:grid;grid-template-columns:1fr 1fr;gap:12px}'
            + '.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:12px}'
            + '.card-label{color:var(--muted);font-size:11px;margin-bottom:4px}'
            + '.card-value{font-size:20px;font-weight:700}'
            + '.card-bar{height:7px;border-radius:999px;margin-top:9px;width:58%;background:var(--p)}'
            + '.card-bar.alt{background:var(--s);width:38%;opacity:.75}'
            + '.panel{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:12px}'
            + '.panel-title{font-weight:600;margin-bottom:10px}'
            + '.btn{display:inline-flex;align-items:center;gap:6px;padding:7px 12px;border:0;'
            + 'border-radius:calc(var(--r) / 1.4);background:var(--p);color:#fff;font-size:12px;font-weight:600}'
            + '.btn.sec{background:var(--s)}'
            + '.btn.ghost{background:transparent;color:var(--text);border:1px solid var(--border)}'
            + '.chip{display:inline-block;padding:2px 8px;border-radius:999px;background:var(--subtle);'
            + 'border:1px solid var(--border);font-size:10.5px;color:var(--muted)}'
            + 'table{width:100%;border-collapse:collapse;font-size:12px}'
            + 'th,td{text-align:left;padding:6px 4px;border-bottom:1px solid var(--border)}'
            + 'th{color:var(--muted);font-weight:500;font-size:11px}'
            + '.field{display:flex;align-items:center;gap:8px;margin-top:10px}'
            + '.input{flex:1;height:30px;border:1px solid var(--border);border-radius:calc(var(--r) / 1.5);'
            + 'background:var(--surface);color:var(--muted);padding:0 9px;font-size:12px;'
            + 'display:flex;align-items:center}'
            + (customCss !== '' ? customCss : '')
            + '</style></head><body>'
            + '<div class="app">'
            + '<aside class="side"><div class="brand">' + brand + '<span>' + escapeHtml(appName) + '</span></div>'
            + nav + '</aside>'
            + '<div class="main">'
            + '<header class="top"><span class="top-title">Канбан-доска</span>'
            + '<span class="top-actions"><span class="top-btn"></span><span class="top-btn"></span>'
            + '<span class="avatar"></span></span></header>'
            + '<div class="content">'
            + '<div class="cards">'
            + '<div class="card"><div class="card-label">Задач в работе</div>'
            + '<div class="card-value">24</div><div class="card-bar"></div></div>'
            + '<div class="card"><div class="card-label">Завершено за неделю</div>'
            + '<div class="card-value">61</div><div class="card-bar alt"></div></div>'
            + '</div>'
            + '<div class="panel"><div class="panel-title">Активные задачи</div>'
            + '<table><thead><tr><th>Задача</th><th>Статус</th></tr></thead><tbody>' + rows + '</tbody></table>'
            + '<div class="field"><span class="btn">Создать задачу</span>'
            + '<span class="btn sec">Отчёт</span><span class="btn ghost">Фильтр</span></div>'
            + '<div class="field"><span class="input">Поиск по задачам…</span></div>'
            + '</div>'
            + '</div></div></div>'
            + '</body></html>';
    }

    function readValue(root, name) {
        var element = root.querySelector('[name="' + name + '"]');
        return element ? element.value : '';
    }

    function init(root) {
        var preview = root.querySelector('[data-skin-preview]');
        var empty = root.querySelector('[data-skin-preview-empty]');

        if (!preview) {
            return;
        }

        var logoPreview = root.querySelector('[data-logo-preview]');

        function currentLogo() {
            if (!logoPreview || logoPreview.hidden) {
                return '';
            }
            return logoPreview.getAttribute('src') || '';
        }

        function render() {
            var config = {
                appName: readValue(root, 'appName'),
                primary: readValue(root, 'primary'),
                secondary: readValue(root, 'secondary'),
                radius: readValue(root, 'borderRadius'),
                font: readValue(root, 'fontFamily'),
                mode: readValue(root, 'mode'),
                customCss: readValue(root, 'customCss'),
                logo: currentLogo()
            };

            preview.srcdoc = buildPreview(config);
            preview.hidden = false;

            if (empty) {
                empty.hidden = true;
            }
        }

        var timer = null;

        function scheduleRender() {
            window.clearTimeout(timer);
            timer = window.setTimeout(render, 140);
        }

        // Colour pickers <-> hex inputs
        Array.prototype.forEach.call(root.querySelectorAll('[data-color-picker]'), function (picker) {
            var key = picker.getAttribute('data-color-picker');
            var text = root.querySelector('[data-color-text="' + key + '"]');

            picker.addEventListener('input', function () {
                if (text) {
                    text.value = picker.value;
                }
                scheduleRender();
            });

            if (text) {
                text.addEventListener('input', function () {
                    if (HEX_RE.test(text.value.trim())) {
                        picker.value = text.value.trim();
                    }
                    scheduleRender();
                });
            }
        });

        // Range <-> number
        Array.prototype.forEach.call(root.querySelectorAll('[data-range-for]'), function (range) {
            var name = range.getAttribute('data-range-for');
            var number = root.querySelector('[name="' + name + '"]');

            range.addEventListener('input', function () {
                if (number) {
                    number.value = range.value;
                }
                scheduleRender();
            });

            if (number) {
                number.addEventListener('input', function () {
                    range.value = number.value;
                    scheduleRender();
                });
            }
        });

        // Palette presets
        var presets = root.querySelector('[data-skin-presets]');
        if (presets) {
            Array.prototype.forEach.call(presets.querySelectorAll('[data-primary]'), function (button) {
                button.addEventListener('click', function () {
                    var primary = safeColor(button.getAttribute('data-primary'), '#0052CC');
                    var secondary = safeColor(button.getAttribute('data-secondary'), '#7C3AED');
                    var primaryText = root.querySelector('[data-color-text="primary"]');
                    var secondaryText = root.querySelector('[data-color-text="secondary"]');
                    var primaryPicker = root.querySelector('[data-color-picker="primary"]');
                    var secondaryPicker = root.querySelector('[data-color-picker="secondary"]');

                    if (primaryText) { primaryText.value = primary; }
                    if (secondaryText) { secondaryText.value = secondary; }
                    if (primaryPicker) { primaryPicker.value = primary; }
                    if (secondaryPicker) { secondaryPicker.value = secondary; }

                    scheduleRender();
                });
            });
        }

        // Logo / favicon file previews
        function bindUpload(fileSelector, imageSelector, emptySelector, urlSelector) {
            var input = root.querySelector(fileSelector);
            var image = root.querySelector(imageSelector);
            var placeholder = root.querySelector(emptySelector);
            var url = root.querySelector(urlSelector);

            if (input) {
                input.addEventListener('change', function () {
                    var file = input.files && input.files[0];

                    if (!file) {
                        return;
                    }

                    var reader = new FileReader();

                    reader.onload = function () {
                        if (image) {
                            image.src = String(reader.result || '');
                            image.hidden = false;
                        }
                        if (placeholder) {
                            placeholder.hidden = true;
                        }
                        if (url) {
                            url.value = '';
                        }
                        scheduleRender();
                    };

                    reader.readAsDataURL(file);
                });
            }

            if (url) {
                url.addEventListener('input', function () {
                    if (image && safeUrl(url.value) !== '') {
                        image.src = safeUrl(url.value);
                        image.hidden = false;
                        placeholder.hidden = true;
                    }
                    scheduleRender();
                });
            }
        }

        bindUpload('[data-logo-file]', '[data-logo-preview]', '[data-logo-empty]', '[name="logo_url"]');
        bindUpload('[data-favicon-file]', '[data-favicon-preview]', '[data-favicon-empty]', '[name="favicon_url"]');

        root.addEventListener('input', scheduleRender);
        root.addEventListener('change', scheduleRender);

        var form = root.closest('form');
        if (form) {
            form.addEventListener('submit', function () {
                render();
            });
        }

        render();
    }

    function boot() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-skin-editor]'), init);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
