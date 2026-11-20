@extends('admin.layout')

@section('title', 'Этапы CRM')

@section('content')
<h1 style="margin-bottom: 1.5rem; color: #172B4D;">📈 Этапы CRM (воронка продаж)</h1>

@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif
@if(session('error'))
    <div class="alert alert-error">{{ session('error') }}</div>
@endif
@if($errors->any())
    <div class="alert alert-error">
        <ul style="margin: 0; padding-left: 1.25rem;">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="form-card" style="max-width: 100%; margin-bottom: 2rem;">
    <h2>Добавить этап</h2>
    <form method="POST" action="{{ route('admin.crm-stages.store') }}">
        @csrf
        <div style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-end;">
            <div class="form-group" style="margin-bottom: 0; min-width: 220px;">
                <label>Название</label>
                <input type="text" name="name" value="{{ old('name') }}" required>
            </div>
            <div class="form-group" style="margin-bottom: 0; min-width: 100px;">
                <label>Порядок</label>
                <input type="number" name="sort_order" value="{{ old('sort_order', 0) }}" min="0">
            </div>
            <div class="form-group" style="margin-bottom: 0; min-width: 90px;">
                <label>Цвет</label>
                <input type="color" name="color" value="{{ old('color', '#3390ec') }}">
            </div>
            <div class="form-group" style="margin-bottom: 0;">
                <label><input type="checkbox" name="is_won" value="1"> Успех</label>
            </div>
            <div class="form-group" style="margin-bottom: 0;">
                <label><input type="checkbox" name="is_lost" value="1"> Провал</label>
            </div>
            <div class="form-group" style="margin-bottom: 0;">
                <button type="submit" class="btn btn-primary">➕ Добавить</button>
            </div>
        </div>
    </form>
</div>

<div class="form-card" style="max-width: 100%;">
    <h2>Текущие этапы</h2>
    <table style="width: 100%; border-collapse: collapse;">
        <thead>
            <tr style="text-align: left; border-bottom: 1px solid #e4e9ed;">
                <th style="padding: 0.5rem;">Название</th>
                <th style="padding: 0.5rem;">Порядок</th>
                <th style="padding: 0.5rem;">Цвет</th>
                <th style="padding: 0.5rem;">Успех</th>
                <th style="padding: 0.5rem;">Провал</th>
                <th style="padding: 0.5rem;">Сделок</th>
                <th style="padding: 0.5rem;"></th>
            </tr>
        </thead>
        <tbody>
            @foreach($stages as $stage)
                <tr style="border-bottom: 1px solid #f0f2f5;">
                    <td colspan="7" style="padding: 0;">
                        <form method="POST" action="{{ route('admin.crm-stages.update', $stage->id) }}" style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; padding: 0.4rem 0.5rem;">
                            @csrf
                            @method('PATCH')
                            <input type="text" name="name" value="{{ $stage->name }}" required style="min-width: 180px; padding: 0.35rem;">
                            <input type="number" name="sort_order" value="{{ $stage->sort_order }}" min="0" style="width: 80px; padding: 0.35rem;">
                            <input type="color" name="color" value="{{ $stage->color }}" style="width: 48px; height: 32px; padding: 0;">
                            <label><input type="checkbox" name="is_won" value="1" {{ $stage->is_won ? 'checked' : '' }}> Успех</label>
                            <label><input type="checkbox" name="is_lost" value="1" {{ $stage->is_lost ? 'checked' : '' }}> Провал</label>
                            <span style="color: #6B778C; font-size: 0.8125rem;">{{ $stage->deals_count }} сделок</span>
                            <span style="flex: 1;"></span>
                            <button type="submit" class="btn btn-secondary btn-sm">💾 Сохранить</button>
                        </form>
                        <form method="POST" action="{{ route('admin.crm-stages.destroy', $stage->id) }}" style="padding: 0 0.5rem 0.4rem;" onsubmit="return confirm('Удалить этап «{{ $stage->name }}»?');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-danger btn-sm">🗑️ Удалить</button>
                        </form>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endsection
