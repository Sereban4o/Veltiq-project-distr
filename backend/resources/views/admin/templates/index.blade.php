@extends('admin.layout')

@section('title', 'Шаблоны')

@section('content')
<h1 style="margin-bottom: 1.5rem;">🧩 Шаблоны</h1>

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

<div class="form-card" id="template-upload" style="max-width: 100%; margin-bottom: 2rem; transition: border-color 0.15s;">
    <h2>Установить шаблон</h2>
    <p style="color: #6B778C; font-size: 0.875rem; margin-bottom: 1rem;">
        ZIP-архив с <code>manifest.json</code> и HTML-страницами (до 50 МБ).
        Перетащите архив в эту карточку или выберите файл. Внутри допускаются только
        HTML/CSS/JS, изображения и шрифты; PHP-файлы и символические ссылки отклоняются.
        Формат описан в <code>docs/13-template-system.md</code>.
    </p>
    <form method="POST" action="{{ route('admin.templates.upload') }}" enctype="multipart/form-data">
        @csrf
        <div style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-end;">
            <div class="form-group" style="margin-bottom: 0; min-width: 260px;">
                <label>Архив шаблона</label>
                <input type="file" name="archive" accept=".zip,application/zip" required>
            </div>
            <div class="form-group" style="margin-bottom: 0;">
                <label>Название (необязательно)</label>
                <input type="text" name="name" maxlength="128" placeholder="Из manifest.json">
            </div>
            <div class="form-group" style="margin-bottom: 0;">
                <label>Автор (необязательно)</label>
                <input type="text" name="author" maxlength="128" placeholder="Из manifest.json">
            </div>
            <button type="submit" class="btn btn-primary">Загрузить</button>
        </div>
    </form>
</div>

<div class="data-table">
    <table>
        <thead>
            <tr>
                <th>Шаблон</th>
                <th>Версия</th>
                <th>Автор</th>
                <th>Состояние</th>
                <th>Создан</th>
                <th>Действия</th>
            </tr>
        </thead>
        <tbody>
            @forelse($templates as $template)
                <tr @class(['template-active-row' => $template->is_active])>
                    <td>
                        <strong>{{ $template->name }}</strong>
                        <div style="font-family: monospace; font-size: 0.75rem; color: #6B778C;">{{ $template->slug }}</div>
                        @if($template->description)
                            <div style="font-size: 0.8rem; color: #6B778C; margin-top: 0.25rem;">{{ $template->description }}</div>
                        @endif
                    </td>
                    <td>{{ $template->version }}</td>
                    <td>{{ $template->author ?: '—' }}</td>
                    <td>
                        @if($template->is_active)
                            <span class="badge badge-success">Активен</span>
                        @else
                            <span class="badge badge-info">Неактивен</span>
                        @endif
                        @if($template->is_builtin)
                            <span class="badge badge-warning">Встроенный</span>
                        @endif
                    </td>
                    <td>{{ $template->created_at?->format('d.m.Y H:i') }}</td>
                    <td>
                        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center;">
                            @if(! $template->is_active)
                                <form method="POST" action="{{ route('admin.templates.activate', $template) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-primary">Активировать</button>
                                </form>
                            @endif

                            @if($template->is_active && ! $template->is_builtin)
                                <form method="POST" action="{{ route('admin.templates.deactivate', $template) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-secondary">Деактивировать</button>
                                </form>
                            @endif

                            @if(! $template->is_builtin)
                                <a class="btn btn-sm btn-secondary" href="{{ $template->url }}" target="_blank" rel="noopener">Открыть</a>
                                <form method="POST" action="{{ route('admin.templates.destroy', $template) }}" onsubmit="return confirm('Удалить шаблон и его файлы?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-danger">Удалить</button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" style="text-align: center; color: #6B778C;">Шаблонов пока нет.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<p style="margin-top: 1rem; color: #6B778C; font-size: 0.875rem;">
    Активный шаблон обслуживается по адресу <code>/template/{slug}</code>; чтобы вернуться к
    стандартному React-интерфейсу, активируйте «Veltiq SPA (встроенный)».
</p>
@endsection

@push('styles')
<style>
    .template-active-row { box-shadow: inset 3px 0 0 #36B37E; }
</style>
@endpush

@push('scripts')
<script>
    (function () {
        var card = document.getElementById('template-upload');
        if (!card) return;

        var form = card.querySelector('form');
        var input = form ? form.querySelector('input[type="file"]') : null;
        if (!form || !input) return;

        ['dragenter', 'dragover'].forEach(function (type) {
            card.addEventListener(type, function (event) {
                event.preventDefault();
                card.style.borderColor = '#0052CC';
            });
        });

        ['dragleave', 'drop'].forEach(function (type) {
            card.addEventListener(type, function (event) {
                event.preventDefault();
                card.style.borderColor = '';
            });
        });

        card.addEventListener('drop', function (event) {
            var files = event.dataTransfer ? event.dataTransfer.files : null;
            if (files && files.length > 0) {
                input.files = files;
            }
        });
    })();
</script>
@endpush