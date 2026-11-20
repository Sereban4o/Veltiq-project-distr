@extends('admin.layout')

@section('title', 'Виджеты')

@section('content')
<h1 style="margin-bottom: 1.5rem; color: #172B4D;">🧩 Виджеты (встраиваемые панели)</h1>

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
    <h2>Настройка</h2>
    <p style="color: #6B778C; font-size: 0.875rem; margin-bottom: 1.25rem;">
        По одному виджету на строку: <code>Название | https://адрес | высота</code>. Высота необязательна
        (по умолчанию 480 px, допустимо 240–2000). Разрешены только <code>http</code>/<code>https</code>;
        виджет отображается в песочнице iframe и не имеет доступа к приложению.
    </p>

    <form method="POST" action="{{ route('admin.widgets.update') }}">
        @csrf
        @method('PUT')

        <div class="form-group">
            <label>Виджеты</label>
            <textarea name="widgets" rows="10" style="font-family: monospace; font-size: 0.8125rem;"
                      placeholder="Grafana | https://grafana.example.com/d/abc?kiosk | 600">{{ old('widgets', $textarea) }}</textarea>
        </div>

        <button type="submit" class="btn btn-primary">💾 Сохранить</button>
    </form>
</div>

<div class="form-card" style="max-width: 900px; margin-top: 1.5rem;">
    <h2>Активные виджеты</h2>
    @forelse($widgets as $widget)
        <div style="display: flex; justify-content: space-between; gap: 1rem; padding: 0.6rem 0; border-bottom: 1px solid #EBECF0;">
            <div style="min-width: 0;">
                <strong>{{ $widget['title'] }}</strong>
                <div style="font-family: monospace; font-size: 0.8125rem; color: #6B778C; overflow-wrap: anywhere;">
                    {{ $widget['url'] }}
                </div>
            </div>
            <span class="badge badge-info">{{ $widget['height'] }} px</span>
        </div>
    @empty
        <p style="color: #6B778C;">Виджеты не настроены — пункт меню на клиенте скрыт.</p>
    @endforelse
</div>
@endsection
