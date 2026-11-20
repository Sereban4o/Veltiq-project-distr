@extends('admin.layout')

@section('title', 'Редактировать пользователя')

@section('content')
<div class="form-card">
    <h2>✏️ Редактировать пользователя</h2>
    
    <form method="POST" action="{{ route('admin.users.update', $user->id) }}">
        <input type="hidden" name="_token" value="{{ csrf_token() }}">
        <input type="hidden" name="_method" value="PATCH">
        
        <div class="form-group">
            <label>Имя *</label>
            <input type="text" name="first_name" value="{{ old('first_name', $user->first_name) }}" required>
        </div>
        
        <div class="form-group">
            <label>Фамилия *</label>
            <input type="text" name="last_name" value="{{ old('last_name', $user->last_name) }}" required>
        </div>
        
        <div class="form-group">
            <label>Email *</label>
            <input type="email" name="email" value="{{ old('email', $user->email) }}" required>
        </div>
        
        <div class="form-group">
            <label>Новый пароль (оставьте пустым, если не меняете)</label>
            <input type="password" name="password">
        </div>
        
        <div class="form-group">
            <label>Подтверждение пароля</label>
            <input type="password" name="password_confirmation">
        </div>
        
        <div class="form-group">
            <label>Роль *</label>
            <select name="role_id" required>
                @foreach($roles as $role)
                    <option value="{{ $role->id }}" {{ old('role_id', $user->role_id) == $role->id ? 'selected' : '' }}>
                        {{ $role->name }}
                    </option>
                @endforeach
            </select>
        </div>
        
        <div class="form-group">
            <label>Статус *</label>
            <select name="status" required>
                <option value="active" {{ old('status', $user->status) === 'active' ? 'selected' : '' }}>Активный</option>
                <option value="suspended" {{ old('status', $user->status) === 'suspended' ? 'selected' : '' }}>Заблокирован</option>
                <option value="pending" {{ old('status', $user->status) === 'pending' ? 'selected' : '' }}>Ожидает</option>
            </select>
        </div>

        <h3 style="margin-top: 2rem;">📬 Почтовый ящик</h3>
        <p style="color: #666; font-size: 0.9rem; margin-top: 0;">
            Настройки подключения к почте пользователя. Пароли оставьте пустыми, чтобы не изменять сохранённые.
        </p>

        <div class="form-group">
            <label>Email ящика</label>
            <input type="email" name="mail_email" value="{{ old('mail_email', $mailAccount->email ?? $user->email) }}">
        </div>

        <div class="form-group">
            <label>Имя отправителя</label>
            <input type="text" name="mail_display_name" value="{{ old('mail_display_name', $mailAccount->display_name ?? '') }}">
        </div>

        <div class="form-group">
            <label>Протокол входящей почты</label>
            <select name="mail_incoming_protocol">
                <option value="imap" {{ old('mail_incoming_protocol', $mailAccount->incoming_protocol ?? 'imap') === 'imap' ? 'selected' : '' }}>IMAP</option>
                <option value="pop" {{ old('mail_incoming_protocol', $mailAccount->incoming_protocol ?? 'imap') === 'pop' ? 'selected' : '' }}>POP3</option>
            </select>
        </div>

        <div id="mail-imap-block">
        <h4>Входящие — IMAP</h4>
        <div class="form-group">
            <label>IMAP хост</label>
            <input type="text" name="imap_host" value="{{ old('imap_host', $mailAccount->imap_host ?? '') }}">
        </div>
        <div class="form-group">
            <label>IMAP порт</label>
            <input type="number" name="imap_port" value="{{ old('imap_port', $mailAccount->imap_port ?? 993) }}">
        </div>
        <div class="form-group">
            <label>IMAP шифрование</label>
            <select name="imap_encryption">
                @php($imapEnc = old('imap_encryption', $mailAccount->imap_encryption ?? 'ssl'))
                <option value="ssl" {{ $imapEnc === 'ssl' ? 'selected' : '' }}>SSL</option>
                <option value="tls" {{ $imapEnc === 'tls' ? 'selected' : '' }}>STARTTLS</option>
                <option value="none" {{ $imapEnc === 'none' ? 'selected' : '' }}>Без шифрования</option>
            </select>
        </div>
        <div class="form-group">
            <label>IMAP логин</label>
            <input type="text" name="imap_username" value="{{ old('imap_username', $mailAccount->imap_username ?? '') }}">
        </div>
        <div class="form-group">
            <label>IMAP пароль</label>
            <input type="password" name="imap_password" autocomplete="new-password" placeholder="{{ $mailAccount?->imap_password ? '•••••••• (сохранён)' : '' }}">
        </div>

        </div>

        <div id="mail-pop-block">
        <h4>Входящие — POP3</h4>
        <div class="form-group">
            <label>POP3 хост</label>
            <input type="text" name="pop_host" value="{{ old('pop_host', $mailAccount->pop_host ?? '') }}">
        </div>
        <div class="form-group">
            <label>POP3 порт</label>
            <input type="number" name="pop_port" value="{{ old('pop_port', $mailAccount->pop_port ?? 995) }}">
        </div>
        <div class="form-group">
            <label>POP3 шифрование</label>
            <select name="pop_encryption">
                @php($popEnc = old('pop_encryption', $mailAccount->pop_encryption ?? 'ssl'))
                <option value="ssl" {{ $popEnc === 'ssl' ? 'selected' : '' }}>SSL</option>
                <option value="tls" {{ $popEnc === 'tls' ? 'selected' : '' }}>STARTTLS</option>
                <option value="none" {{ $popEnc === 'none' ? 'selected' : '' }}>Без шифрования</option>
            </select>
        </div>
        <div class="form-group">
            <label>POP3 логин</label>
            <input type="text" name="pop_username" value="{{ old('pop_username', $mailAccount->pop_username ?? '') }}">
        </div>
        <div class="form-group">
            <label>POP3 пароль</label>
            <input type="password" name="pop_password" autocomplete="new-password" placeholder="{{ $mailAccount?->pop_password ? '•••••••• (сохранён)' : '' }}">
        </div>

        </div>

        <h4>Исходящие — SMTP</h4>
        <div class="form-group">
            <label>SMTP хост</label>
            <input type="text" name="smtp_host" value="{{ old('smtp_host', $mailAccount->smtp_host ?? '') }}">
        </div>
        <div class="form-group">
            <label>SMTP порт</label>
            <input type="number" name="smtp_port" value="{{ old('smtp_port', $mailAccount->smtp_port ?? 587) }}">
        </div>
        <div class="form-group">
            <label>SMTP шифрование</label>
            <select name="smtp_encryption">
                @php($smtpEnc = old('smtp_encryption', $mailAccount->smtp_encryption ?? 'tls'))
                <option value="ssl" {{ $smtpEnc === 'ssl' ? 'selected' : '' }}>SSL</option>
                <option value="tls" {{ $smtpEnc === 'tls' ? 'selected' : '' }}>STARTTLS</option>
                <option value="none" {{ $smtpEnc === 'none' ? 'selected' : '' }}>Без шифрования</option>
            </select>
        </div>
        <div class="form-group">
            <label>SMTP логин</label>
            <input type="text" name="smtp_username" value="{{ old('smtp_username', $mailAccount->smtp_username ?? '') }}">
        </div>
        <div class="form-group">
            <label>SMTP пароль</label>
            <input type="password" name="smtp_password" autocomplete="new-password" placeholder="{{ $mailAccount?->smtp_password ? '•••••••• (сохранён)' : '' }}">
        </div>
        
        <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
            <button type="submit" class="btn btn-primary">💾 Сохранить</button>
            <a href="{{ route('admin.users.index') }}" class="btn btn-secondary">Отмена</a>
        </div>
    </form>
</div>

<script>
(function () {
    const select = document.querySelector('select[name="mail_incoming_protocol"]');
    const imapBlock = document.getElementById('mail-imap-block');
    const popBlock = document.getElementById('mail-pop-block');
    if (!select || !imapBlock || !popBlock) return;

    function toggle() {
        const useImap = select.value === 'imap';
        imapBlock.style.display = useImap ? '' : 'none';
        popBlock.style.display = useImap ? 'none' : '';
        // Disabled inputs are not submitted, so only the chosen protocol is saved.
        imapBlock.querySelectorAll('input, select').forEach(function (el) { el.disabled = !useImap; });
        popBlock.querySelectorAll('input, select').forEach(function (el) { el.disabled = useImap; });
    }

    select.addEventListener('change', toggle);
    toggle();
})();
</script>
@endsection
