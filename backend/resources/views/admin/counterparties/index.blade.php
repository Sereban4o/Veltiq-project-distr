@extends('admin.layout')

@section('title', 'Контрагенты')

@section('content')
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
    <h1 style="color: #172B4D; margin: 0;">🏢 Контрагенты (1С)</h1>
    <div style="display: flex; gap: 0.5rem; align-items: center;">
        <form method="POST" action="{{ route('admin.counterparties.sync') }}" style="display: inline;">
            @csrf
            <button type="submit" class="btn btn-primary" @disabled(!$configured)>🔄 Загрузить из 1С</button>
        </form>
    </div>
</div>

@if(!$configured)
    <div class="alert alert-error">
        Сервис 1С не настроен. Задайте в окружении <code>ONE_C_COUNTERPARTIES_URL</code>,
        <code>ONE_C_COUNTERPARTIES_LOGIN</code> и <code>ONE_C_COUNTERPARTIES_PASSWORD</code>.
    </div>
@endif

<div style="display: flex; gap: 2rem; flex-wrap: wrap; margin-bottom: 1.5rem; font-size: 0.875rem; color: #42526E;">
    <div><strong>Связано с 1С:</strong> {{ $linkedCount }}</div>
    <div><strong>Помечено на удаление в 1С:</strong> {{ $deletedCount }}</div>
    <div>
        <strong>Последняя синхронизация:</strong>
        {{ $syncedAt ? \Illuminate\Support\Carbon::parse($syncedAt)->format('d.m.Y H:i') : '—' }}
    </div>
</div>

<div class="form-card" style="max-width: 900px; margin-bottom: 1.5rem;">
    <h2>Подключение к сервису 1С</h2>
    <p style="color: #6B778C; font-size: 0.875rem; margin-top: 0;">
        Адрес SOAP-сервиса контрагентов и учётные данные публикации (аутентификация 1С:Предприятия).
        Пример: <code>http://server/base/ws/CounterpartiesExchange.1cws</code>. Пароль хранится зашифрованным.
    </p>
    <form method="POST" action="{{ route('admin.counterparties.settings') }}">
        @csrf
        <div class="form-group">
            <label>Адрес сервиса</label>
            <input type="text" name="url" value="{{ old('url', $url) }}" placeholder="http://server/base/ws/CounterpartiesExchange.1cws">
        </div>
        <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
            <div class="form-group" style="flex: 1; min-width: 220px;">
                <label>Логин</label>
                <input type="text" name="login" value="{{ old('login', $login) }}" autocomplete="off">
            </div>
            <div class="form-group" style="flex: 1; min-width: 220px;">
                <label>Пароль</label>
                <input type="password" name="password" value="" autocomplete="new-password" placeholder="{{ $password_set ? 'оставьте пустым, чтобы не менять' : 'пароль' }}">
                @if($password_set)
                    <label style="display: flex; align-items: center; gap: 0.5rem; font-weight: 400; margin-top: 0.5rem;">
                        <input type="checkbox" name="clear_password" value="1"> Очистить сохранённый пароль
                    </label>
                @endif
            </div>
        </div>
        <div class="form-group">
            <label style="display: flex; align-items: center; gap: 0.5rem; font-weight: 400;">
                <input type="checkbox" name="verify_tls" value="1" @checked(old('verify_tls', $verify))> Проверять TLS-сертификат (снимите для self-signed)
            </label>
        </div>
        <button type="submit" class="btn btn-primary">💾 Сохранить настройки</button>
    </form>
</div>

<form method="GET" action="{{ route('admin.counterparties.index') }}" class="filters">
    <input type="text" name="search" placeholder="Поиск по наименованию или ИНН..." value="{{ $search }}" style="flex: 1; max-width: 320px;">
    <button type="submit" class="btn btn-primary btn-sm">🔍 Найти</button>
    <a href="{{ route('admin.counterparties.index') }}" class="btn btn-secondary btn-sm">✕ Сбросить</a>
</form>

<div class="data-table">
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Наименование</th>
                <th>Полное наименование</th>
                <th>ИНН</th>
                <th>КПП</th>
                <th>Юр/Физ</th>
                <th>Телефон</th>
                <th>Email</th>
                <th>Код 1С</th>
                <th>Обновлено</th>
            </tr>
        </thead>
        <tbody>
            @forelse($companies as $company)
            <tr>
                <td>{{ $company->id }}</td>
                <td>
                    <strong>{{ $company->name }}</strong>
                    @if($company->ref_1c)
                        <span class="badge badge-info">1С</span>
                    @endif
                    @if($company->deleted_in_1c)
                        <span class="badge badge-danger">Удалён в 1С</span>
                    @endif
                </td>
                <td>{{ $company->full_name ?: '—' }}</td>
                <td>{{ $company->inn ?: '—' }}</td>
                <td>{{ $company->kpp ?: '—' }}</td>
                <td>{{ $company->legal_physical_person ?: '—' }}</td>
                <td>{{ $company->phone ?: '—' }}</td>
                <td>{{ $company->email ?: '—' }}</td>
                <td>{{ $company->code_1c ?: '—' }}</td>
                <td>{{ $company->synced_at ? $company->synced_at->format('d.m.Y H:i') : '—' }}</td>
            </tr>
            @empty
            <tr>
                <td colspan="10" style="text-align: center; padding: 2rem; color: #6B778C;">Контрагенты не найдены</td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="pagination">
    {{ $companies->links() }}
</div>
@endsection
