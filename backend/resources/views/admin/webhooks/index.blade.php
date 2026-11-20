@extends('admin.layout')

@section('title', 'Вебхуки')

@section('content')
<h1 style="margin-bottom: 1.5rem; color: #172B4D;">🔌 Вебхуки (интеграции)</h1>

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
    <h2>Новый вебхук</h2>
    <p style="color: #6B778C; font-size: 0.875rem; margin-bottom: 1.25rem;">
        События задачи отправляются POST-запросом с JSON. Если задан «секрет», запрос подписывается
        HMAC-SHA256 в заголовке <code>X-Veltiq-Signature</code> (значение <code>sha256=…</code>).
    </p>
    <form method="POST" action="{{ route('admin.webhooks.store') }}">
        @csrf
        <div style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-end;">
            <div class="form-group" style="margin-bottom: 0; min-width: 180px;">
                <label>Название</label>
                <input type="text" name="name" value="{{ old('name') }}" placeholder="CRM sync" required>
            </div>
            <div class="form-group" style="margin-bottom: 0; min-width: 320px; flex: 1;">
                <label>URL (http/https)</label>
                <input type="text" name="url" value="{{ old('url') }}" placeholder="https://example.com/webhooks/veltiq" required>
            </div>
            <div class="form-group" style="margin-bottom: 0; min-width: 200px;">
                <label>Секрет (необязательно)</label>
                <input type="password" name="secret" value="" autocomplete="new-password">
            </div>
        </div>

        <div class="form-group" style="margin-top: 1rem;">
            <label>События</label>
            <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
                @foreach($events as $event)
                    <label style="display: flex; align-items: center; gap: 0.35rem;">
                        <input type="checkbox" name="events[]" value="{{ $event }}" @checked(in_array($event, old('events', ['issue.created']), true))>
                        {{ $event }}
                    </label>
                @endforeach
            </div>
        </div>

        <div class="form-group">
            <label style="display: flex; align-items: center; gap: 0.4rem;">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', true))>
                Активен
            </label>
        </div>

        <button type="submit" class="btn btn-primary">➕ Создать</button>
    </form>
</div>

<div class="data-table">
    <table>
        <thead>
            <tr>
                <th>Название</th>
                <th>URL</th>
                <th>События</th>
                <th>Статус</th>
                <th>Действия</th>
            </tr>
        </thead>
        <tbody>
            @forelse($webhooks as $webhook)
                <tr>
                    <td><strong>{{ $webhook->name }}</strong></td>
                    <td style="font-family: monospace; font-size: 0.8125rem;">{{ $webhook->url }}</td>
                    <td>
                        @foreach($webhook->events ?? [] as $event)
                            <span class="badge badge-info">{{ $event }}</span>
                        @endforeach
                    </td>
                    <td>
                        @if($webhook->is_active)
                            <span class="badge badge-success">активен</span>
                        @else
                            <span class="badge badge-danger">выключен</span>
                        @endif
                        @if($webhook->last_status)
                            <div style="font-size: 0.75rem; color: #6B778C; margin-top: 0.25rem;">
                                HTTP {{ $webhook->last_status }}
                                @if($webhook->last_called_at) · {{ $webhook->last_called_at->format('d.m.Y H:i') }} @endif
                                @if($webhook->failures > 0) · сбоев: {{ $webhook->failures }} @endif
                            </div>
                        @endif
                    </td>
                    <td>
                        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                            <form method="POST" action="{{ route('admin.webhooks.test', $webhook) }}" style="display: inline;">
                                @csrf
                                <button type="submit" class="btn btn-secondary btn-sm">Проверить</button>
                            </form>

                            <form method="POST" action="{{ route('admin.webhooks.update', $webhook) }}" style="display: inline;">
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="name" value="{{ $webhook->name }}">
                                <input type="hidden" name="url" value="{{ $webhook->url }}">
                                @foreach($webhook->events ?? [] as $event)
                                    <input type="hidden" name="events[]" value="{{ $event }}">
                                @endforeach
                                <input type="hidden" name="is_active" value="{{ $webhook->is_active ? '0' : '1' }}">
                                <button type="submit" class="btn btn-secondary btn-sm">
                                    {{ $webhook->is_active ? 'Выключить' : 'Включить' }}
                                </button>
                            </form>

                            <form method="POST" action="{{ route('admin.webhooks.destroy', $webhook) }}" style="display: inline;"
                                  onsubmit="return confirm('Удалить вебхук {{ $webhook->name }}?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-danger btn-sm">Удалить</button>
                            </form>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" style="text-align: center; padding: 2rem; color: #6B778C;">Вебхуков пока нет</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
