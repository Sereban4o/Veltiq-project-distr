@extends('admin.layout')

@section('title', 'Доп. поля')

@section('content')
<h1 style="margin-bottom: 1.5rem; color: #172B4D;">➕ Дополнительные поля задач</h1>

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
    <h2>Новое поле</h2>
    <p style="color: #6B778C; font-size: 0.875rem; margin-bottom: 1.25rem;">
        Ключ — латиница/цифры/подчёркивание (используется в API и в данных задачи). Для типа
        «список» перечислите варианты по одному в строке. Значения хранятся в задаче и не влияют
        на другие поля.
    </p>
    <form method="POST" action="{{ route('admin.custom-fields.store') }}">
        @csrf
        <div style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-end;">
            <div class="form-group" style="margin-bottom: 0; min-width: 160px;">
                <label>Ключ</label>
                <input type="text" name="key" value="{{ old('key') }}" placeholder="team_code" required>
            </div>
            <div class="form-group" style="margin-bottom: 0; min-width: 200px;">
                <label>Название</label>
                <input type="text" name="label" value="{{ old('label') }}" placeholder="Код команды">
            </div>
            <div class="form-group" style="margin-bottom: 0; min-width: 150px;">
                <label>Тип</label>
                <select name="type">
                    @foreach($types as $type)
                        <option value="{{ $type }}" @selected(old('type') === $type)>{{ $type }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-group" style="margin-bottom: 0; min-width: 120px;">
                <label>Порядок</label>
                <input type="number" name="position" value="{{ old('position', 0) }}">
            </div>
            <div class="form-group" style="margin-bottom: 0;">
                <label style="display: flex; align-items: center; gap: 0.4rem;">
                    <input type="checkbox" name="required" value="1" @checked(old('required'))>
                    Обязательное
                </label>
            </div>
        </div>

        <div class="form-group" style="margin-top: 1rem; max-width: 420px;">
            <label>Варианты (только для типа «список»)</label>
            <textarea name="options" rows="3" style="font-family: monospace; font-size: 0.8125rem;">{{ old('options') }}</textarea>
        </div>

        <button type="submit" class="btn btn-primary">➕ Добавить</button>
    </form>
</div>

<div class="data-table">
    <table>
        <thead>
            <tr>
                <th>Ключ</th>
                <th>Название</th>
                <th>Тип</th>
                <th>Обяз.</th>
                <th>Порядок</th>
                <th>Статус</th>
                <th>Действия</th>
            </tr>
        </thead>
        <tbody>
            @forelse($fields as $field)
                <tr>
                    <td style="font-family: monospace; font-size: 0.8125rem;">{{ $field->key }}</td>
                    <td>{{ $field->label }}</td>
                    <td><span class="badge badge-info">{{ $field->type }}</span></td>
                    <td>{{ $field->required ? 'да' : '—' }}</td>
                    <td>{{ $field->position }}</td>
                    <td>
                        @if($field->is_active)
                            <span class="badge badge-success">активно</span>
                        @else
                            <span class="badge badge-danger">выключено</span>
                        @endif
                    </td>
                    <td>
                        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                            <form method="POST" action="{{ route('admin.custom-fields.update', $field) }}" style="display: inline;">
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="is_active" value="{{ $field->is_active ? '0' : '1' }}">
                                <button type="submit" class="btn btn-secondary btn-sm">
                                    {{ $field->is_active ? 'Выключить' : 'Включить' }}
                                </button>
                            </form>

                            <form method="POST" action="{{ route('admin.custom-fields.destroy', $field) }}" style="display: inline;"
                                  onsubmit="return confirm('Удалить поле {{ $field->key }}?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-danger btn-sm">Удалить</button>
                            </form>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" style="text-align: center; padding: 2rem; color: #6B778C;">Полей пока нет</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
