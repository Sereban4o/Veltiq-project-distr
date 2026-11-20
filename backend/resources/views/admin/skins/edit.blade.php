@extends('admin.layout')

@section('title', 'Скин: ' . $skin->name)

@section('content')
<div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1.5rem; flex-wrap: wrap;">
    <h1 style="color: var(--text); margin: 0;">🎨 {{ $skin->name }}</h1>
    @if($skin->is_active)
        <span class="badge badge-success">Активен</span>
    @else
        <span class="badge badge-info">Не активен</span>
    @endif
    <a href="{{ route('admin.skins.index') }}" class="btn btn-sm btn-secondary" style="margin-left: auto;">← Все скины</a>
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

<form method="POST" action="{{ route('admin.skins.update', $skin) }}" enctype="multipart/form-data">
    @csrf
    @method('PUT')

    @include('admin.skins._form', ['config' => $config, 'skin' => $skin])

    <div class="skin-actions">
        <button type="submit" class="btn btn-primary">💾 Сохранить изменения</button>
        <a href="{{ route('admin.skins.index') }}" class="btn btn-secondary">Отмена</a>
    </div>
</form>

<div class="form-card" style="max-width: 100%; margin-top: 1.5rem;">
    <h2 style="margin-bottom: 1rem; color: var(--text); font-size: 1.05rem;">Действия</h2>
    <div class="skin-actions" style="margin-top: 0;">
        @unless($skin->is_active)
            <form method="POST" action="{{ route('admin.skins.apply', $skin) }}">
                @csrf
                <button type="submit" class="btn btn-primary">✅ Применить скин</button>
            </form>
        @endunless

        <form method="POST" action="{{ route('admin.skins.duplicate', $skin) }}">
            @csrf
            <button type="submit" class="btn btn-secondary">📄 Дублировать</button>
        </form>

        @unless($skin->is_active)
            <form method="POST" action="{{ route('admin.skins.destroy', $skin) }}"
                  onsubmit="return confirm('Удалить скин?');">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-danger">🗑️ Удалить</button>
            </form>
        @endunless
    </div>
    @if($skin->is_active)
        <p class="skin-hint" style="margin: 0.75rem 0 0;">
            Активный скин нельзя удалить — сначала примените другой скин.
        </p>
    @endif
</div>
@endsection
