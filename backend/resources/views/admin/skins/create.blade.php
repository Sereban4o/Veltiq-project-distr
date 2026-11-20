@extends('admin.layout')

@section('title', 'Новый скин')

@section('content')
<h1 style="margin-bottom: 1.5rem; color: var(--text);">🎨 Новый скин</h1>

@if($errors->any())
    <div class="alert alert-error">
        <ul style="margin: 0; padding-left: 1.25rem;">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form method="POST" action="{{ route('admin.skins.store') }}" enctype="multipart/form-data">
    @csrf

    @include('admin.skins._form', ['config' => $config, 'skin' => null])

    <div class="skin-actions">
        <label>
            <input type="checkbox" name="activate" value="1" checked>
            Применить сразу
        </label>
        <button type="submit" class="btn btn-primary">💾 Сохранить скин</button>
        <a href="{{ route('admin.skins.index') }}" class="btn btn-secondary">Отмена</a>
    </div>
</form>
@endsection
