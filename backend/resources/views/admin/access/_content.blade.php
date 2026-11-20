@php
    $scopeLabel = $scopeType === 'project' ? 'проекта' : 'пространства';
    $userNames = $users->mapWithKeys(fn ($u) => [$u->id => trim($u->first_name.' '.$u->last_name).' <'.$u->email.'>']);
@endphp

<div class="form-card" style="max-width: 1100px; margin-bottom: 1.5rem;">
    <h2>➕ Добавить правило</h2>
    <p style="color: #6B778C; margin-bottom: 1rem; font-size: 0.875rem;">
        Правило <strong>deny</strong> запрещает право даже если роль участника его разрешает.
        Правило <strong>allow</strong> выдаёт право дополнительно к роли участника.
        Запрет в конкретном объекте важнее разрешения на глобальном уровне.
    </p>

    <form method="POST" action="{{ route('admin.access.overrides.store') }}">
        @csrf
        <input type="hidden" name="scope_type" value="{{ $scopeType }}">

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem;">
            <div class="form-group">
                <label>Объект</label>
                <select name="scope_id" required>
                    @foreach($scopes as $scope)
                        <option value="{{ $scope->id }}">{{ $scope->name }} ({{ $scope->key }})</option>
                    @endforeach
                </select>
            </div>

            <div class="form-group">
                <label>Кому</label>
                <select name="target" required>
                    <optgroup label="Пользователи">
                        @foreach($users as $user)
                            <option value="user:{{ $user->id }}">{{ trim($user->first_name.' '.$user->last_name) }} &lt;{{ $user->email }}&gt;</option>
                        @endforeach
                    </optgroup>
                    <optgroup label="Роли">
                        @foreach($roles as $role)
                            <option value="role:{{ $role->name }}">{{ $role->name }}</option>
                        @endforeach
                    </optgroup>
                </select>
            </div>

            <div class="form-group">
                <label>Право</label>
                <select name="permission" required>
                    @foreach($catalogue as $group)
                        <optgroup label="{{ $group['label'] }}">
                            @foreach($group['permissions'] as $name => $label)
                                @if(\App\Support\Permissions\PermissionCatalogue::allowsScope($name, $scopeType))
                                    <option value="{{ $name }}">{{ $label }} ({{ $name }})</option>
                                @endif
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
            </div>

            <div class="form-group">
                <label>Эффект</label>
                <select name="effect" required>
                    <option value="allow">Разрешить (allow)</option>
                    <option value="deny">Запретить (deny)</option>
                </select>
            </div>
        </div>

        <button type="submit" class="btn btn-primary">💾 Сохранить правило</button>
    </form>
</div>

<div class="data-table">
    <table>
        <thead>
            <tr>
                <th>Объект</th>
                <th>Кому</th>
                <th>Право</th>
                <th>Эффект</th>
                <th>Создал</th>
                <th>Дата</th>
                <th>Действия</th>
            </tr>
        </thead>
        <tbody>
            @forelse($overrides as $override)
                <tr>
                    <td>
                        @if($override->scope_id)
                            {{ $scopeNames[$override->scope_id] ?? ('#'.$override->scope_id) }}
                        @else
                            <em>Глобально</em>
                        @endif
                    </td>
                    <td>
                        @if($override->target_type === 'user')
                            {{ $userNames[(int) $override->target_value] ?? ('#'.$override->target_value) }}
                        @else
                            Роль: <strong>{{ $override->target_value }}</strong>
                        @endif
                    </td>
                    <td>
                        <strong>{{ \App\Support\Permissions\PermissionCatalogue::label($override->permission) }}</strong>
                        <br><code style="font-size: 0.75rem; color: #6B778C;">{{ $override->permission }}</code>
                    </td>
                    <td>
                        <span class="badge {{ $override->effect === 'deny' ? 'badge-danger' : 'badge-success' }}">
                            {{ $override->effect }}
                        </span>
                    </td>
                    <td>{{ $override->creator?->full_name ?? '—' }}</td>
                    <td>{{ $override->created_at?->format('d.m.Y H:i') }}</td>
                    <td>
                        <form method="POST" action="{{ route('admin.access.overrides.destroy', $override->id) }}"
                              onsubmit="return confirm('Удалить правило?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-danger btn-sm">🗑️</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" style="text-align: center; padding: 2rem; color: #6B778C;">
                        Правил для {{ $scopeLabel === 'проекта' ? 'проектов' : 'пространств' }} пока нет
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
