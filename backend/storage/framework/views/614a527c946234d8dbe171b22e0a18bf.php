<?php $__env->startSection('title', 'Файловое хранилище'); ?>

<?php $__env->startSection('content'); ?>
<h1 style="margin-bottom: 1.5rem; color: #172B4D;">🗂️ Файловое хранилище</h1>

<?php if(session('success')): ?>
    <div class="alert alert-success"><?php echo e(session('success')); ?></div>
<?php endif; ?>
<?php if(session('error')): ?>
    <div class="alert alert-error"><?php echo e(session('error')); ?></div>
<?php endif; ?>
<?php if($errors->any()): ?>
    <div class="alert alert-error">
        <ul style="margin: 0; padding-left: 1.25rem;">
            <?php $__currentLoopData = $errors->all(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $error): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <li><?php echo e($error); ?></li>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </ul>
    </div>
<?php endif; ?>

<div class="form-card" style="max-width: 900px;">
    <h2>Подключение сетевого хранилища</h2>
    <p style="color: #6B778C; font-size: 0.875rem; margin-top: 0;">
        Укажите адрес хранилища — приложение подключится к нему напрямую, без монтирования.
        Поддерживаются SMB/CIFS (Windows/Samba), SFTP, FTP/FTPS и WebDAV:
    </p>
    <ul style="color: #6B778C; font-size: 0.8125rem; margin-top: 0; padding-left: 1.25rem;">
        <li><code>smb://192.168.0.8/сотрудники</code> — общая папка Windows/Samba</li>
        <li><code>sftp://192.168.0.9/home/files</code> — Linux-сервер по SSH</li>
        <li><code>ftp://host/path</code> или <code>ftps://host/path</code> (explicit TLS, порт 21)</li>
        <li><code>https://host/dav/path</code> — WebDAV (например, Nextcloud)</li>
    </ul>
    <p style="color: #6B778C; font-size: 0.8125rem;">
        Логин и пароль указываются отдельно (хранятся в зашифрованном виде). Локальные пути
        и <code>\\server\share</code> в этом поле не поддерживаются.
        <?php if($legacy_active): ?>
            <br><strong style="color: #FF8B00;">Сейчас используется устаревший локальный путь <code><?php echo e($legacy_path); ?></code>. Укажите сетевой адрес, чтобы переключиться.</strong>
        <?php endif; ?>
    </p>

    <form method="POST" action="<?php echo e(route('admin.storage.update')); ?>">
        <?php echo csrf_field(); ?>
        <div class="form-group">
            <label>Адрес хранилища</label>
            <input type="text" name="address" value="<?php echo e(old('address', $address)); ?>" placeholder="smb://сервер/шара или sftp://сервер/путь" required>
        </div>

        <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
            <div class="form-group" style="flex: 1; min-width: 220px;">
                <label>Логин</label>
                <input type="text" name="username" value="<?php echo e(old('username', $username)); ?>" autocomplete="off" placeholder="DOMAIN\user">
            </div>
            <div class="form-group" style="flex: 1; min-width: 220px;">
                <label>Пароль</label>
                <input type="password" name="password" value="" autocomplete="new-password" placeholder="<?php echo e($password_set ? 'оставьте пустым, чтобы не менять' : 'пароль'); ?>">
                <?php if($password_set): ?>
                    <label style="display: flex; align-items: center; gap: 0.5rem; font-weight: 400; margin-top: 0.5rem;">
                        <input type="checkbox" name="clear_password" value="1"> Очистить сохранённый пароль
                    </label>
                <?php endif; ?>
            </div>
        </div>

        <div class="form-group" style="max-width: 320px;">
            <label>Домен / рабочая группа (SMB)</label>
            <input type="text" name="domain" value="<?php echo e(old('domain', $domain)); ?>" autocomplete="off" placeholder="CORP">
        </div>

        <details style="margin: 1rem 0;">
            <summary style="cursor: pointer; color: #0052CC;">Дополнительно (таймауты, проверка TLS)</summary>
            <div style="display: flex; gap: 1rem; flex-wrap: wrap; margin-top: 0.75rem;">
                <div class="form-group" style="min-width: 160px;">
                    <label>Таймаут подключения, с</label>
                    <input type="number" name="connect_timeout" min="1" max="120" value="<?php echo e(old('connect_timeout', $connect_timeout)); ?>">
                </div>
                <div class="form-group" style="min-width: 160px;">
                    <label>Таймаут чтения, с</label>
                    <input type="number" name="read_timeout" min="1" max="600" value="<?php echo e(old('read_timeout', $read_timeout)); ?>">
                </div>
                <div class="form-group" style="min-width: 160px;">
                    <label style="display: flex; align-items: center; gap: 0.5rem; font-weight: 400; margin-top: 1.6rem;">
                        <input type="checkbox" name="verify_tls" value="1" <?php if(old('verify_tls', $verify_tls)): echo 'checked'; endif; ?>> Проверять TLS-сертификат
                    </label>
                </div>
            </div>
        </details>

        <div style="display: flex; gap: 2rem; flex-wrap: wrap; margin: 1rem 0; font-size: 0.875rem;">
            <div>
                <strong>Протокол:</strong>
                <code><?php echo e($protocol ? strtoupper($protocol) : '—'); ?></code>
            </div>
            <div>
                <strong>Адрес:</strong>
                <code><?php echo e($display_root ?? '—'); ?></code>
            </div>
            <div>
                <strong>Пароль:</strong>
                <?php if($password_set): ?>
                    <span style="color: #36B37E;">задан</span>
                <?php else: ?>
                    <span style="color: #97A0AF;">не задан</span>
                <?php endif; ?>
            </div>
        </div>

        <button type="submit" class="btn btn-primary">💾 Сохранить</button>
        <button type="submit" formaction="<?php echo e(route('admin.storage.test')); ?>" class="btn btn-secondary">🔌 Проверить подключение</button>
    </form>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('admin.layout', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /var/www/html/resources/views/admin/storage/index.blade.php ENDPATH**/ ?>