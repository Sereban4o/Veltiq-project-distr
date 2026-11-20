<?php $__env->startSection('title', 'Сборки клиентов'); ?>

<?php $__env->startSection('content'); ?>
<h1 style="margin-bottom: 1.5rem; color: #172B4D;">🏗️ Сборки клиентов (preset)</h1>

<?php if($errors->any()): ?>
    <div class="alert alert-error">
        <ul style="margin: 0; padding-left: 1.25rem;">
            <?php $__currentLoopData = $errors->all(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $error): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <li><?php echo e($error); ?></li>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </ul>
    </div>
<?php endif; ?>

<div class="form-card" style="max-width: 100%; margin-bottom: 2rem;">
    <h2>Подключение GitHub Actions</h2>
    <p style="color: #6B778C; font-size: 0.875rem;">
        Сборка APK/MSI выполняется в CI (на сервере нет Android/Windows toolchain). Нужен токен с правом
        <code>actions:write</code> на репозиторий.
    </p>
    <form method="POST" action="<?php echo e(route('admin.client-builds.settings')); ?>">
        <?php echo csrf_field(); ?>
        <?php echo method_field('PUT'); ?>
        <div style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-end;">
            <div class="form-group" style="margin-bottom: 0; min-width: 240px;">
                <label>Репозиторий (owner/name)</label>
                <input type="text" name="repo" value="<?php echo e(old('repo', $settings['repo'])); ?>" placeholder="owner/repo">
            </div>
            <div class="form-group" style="margin-bottom: 0; min-width: 200px;">
                <label>Workflow-файл</label>
                <input type="text" name="workflow" value="<?php echo e(old('workflow', $settings['workflow'])); ?>" placeholder="build-clients.yml">
            </div>
            <div class="form-group" style="margin-bottom: 0; min-width: 140px;">
                <label>Ref (ветка/тег)</label>
                <input type="text" name="ref" value="<?php echo e(old('ref', $settings['ref'])); ?>" placeholder="main">
            </div>
            <div class="form-group" style="margin-bottom: 0; min-width: 240px; flex: 1;">
                <label>Токен GitHub <?php echo e($settings['token_set'] ? '(сохранён)' : ''); ?></label>
                <input type="password" name="token" value="" autocomplete="new-password"
                       placeholder="<?php echo e($settings['token_set'] ? '•••••••• (оставьте пустым)' : 'ghp_…'); ?>">
            </div>
            <div class="form-group" style="margin-bottom: 0;">
                <label style="display: flex; align-items: center; gap: 0.4rem;">
                    <input type="checkbox" name="clear_token" value="1">
                    Удалить токен
                </label>
            </div>
            <div class="form-group" style="margin-bottom: 0;">
                <button type="submit" class="btn btn-secondary">💾 Сохранить</button>
            </div>
        </div>
    </form>
</div>

<div class="form-card" style="max-width: 100%; margin-bottom: 2rem;">
    <h2>Запустить сборку</h2>
    <form method="POST" action="<?php echo e(route('admin.client-builds.store')); ?>">
        <?php echo csrf_field(); ?>
        <div style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-end;">
            <div class="form-group" style="margin-bottom: 0; min-width: 150px;">
                <label>Бренд</label>
                <input type="text" name="brand" value="<?php echo e(old('brand', 'example')); ?>" required>
            </div>
            <div class="form-group" style="margin-bottom: 0; min-width: 200px;">
                <label>Название приложения</label>
                <input type="text" name="app_name" value="<?php echo e(old('app_name')); ?>" placeholder="Veltiq">
            </div>
            <div class="form-group" style="margin-bottom: 0; min-width: 200px;">
                <label>Сервер (host)</label>
                <input type="text" name="server_host" value="<?php echo e(old('server_host')); ?>" placeholder="veltiq.ru" required>
            </div>
            <div class="form-group" style="margin-bottom: 0; min-width: 100px;">
                <label>Порт</label>
                <input type="number" name="server_port" min="1" max="65535" value="<?php echo e(old('server_port', 443)); ?>" required>
            </div>
            <div class="form-group" style="margin-bottom: 0;">
                <label style="display: flex; align-items: center; gap: 0.4rem;">
                    <input type="checkbox" name="server_https" value="1" <?php if(old('server_https', true)): echo 'checked'; endif; ?>>
                    HTTPS
                </label>
            </div>
        </div>

        <div style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-end; margin-top: 1rem;">
            <div class="form-group" style="margin-bottom: 0; min-width: 200px;">
                <label>ntfy host</label>
                <input type="text" name="ntfy_host" value="<?php echo e(old('ntfy_host')); ?>" placeholder="ntfy.veltiq.ru">
            </div>
            <div class="form-group" style="margin-bottom: 0; min-width: 100px;">
                <label>ntfy порт</label>
                <input type="number" name="ntfy_port" min="1" max="65535" value="<?php echo e(old('ntfy_port', 443)); ?>">
            </div>
            <div class="form-group" style="margin-bottom: 0; min-width: 160px;">
                <label>ntfy пользователь</label>
                <input type="text" name="ntfy_user" value="<?php echo e(old('ntfy_user')); ?>" autocomplete="off">
            </div>
            <div class="form-group" style="margin-bottom: 0; min-width: 160px;">
                <label>ntfy пароль</label>
                <input type="password" name="ntfy_pass" value="" autocomplete="new-password">
            </div>
            <div class="form-group" style="margin-bottom: 0;">
                <label style="display: flex; align-items: center; gap: 0.4rem;">
                    <input type="checkbox" name="ntfy_https" value="1" <?php if(old('ntfy_https', true)): echo 'checked'; endif; ?>>
                    ntfy HTTPS
                </label>
            </div>
            <div class="form-group" style="margin-bottom: 0;">
                <button type="submit" class="btn btn-primary">🚀 Запустить сборку</button>
            </div>
        </div>
    </form>
</div>

<?php if($runsError): ?>
    <div class="alert alert-error">Не удалось получить статусы сборок: <?php echo e($runsError); ?></div>
<?php endif; ?>

<div class="data-table">
    <table>
        <thead>
            <tr>
                <th>Запуск</th>
                <th>Статус</th>
                <th>Результат</th>
                <th>Создан</th>
                <th>Ссылка</th>
            </tr>
        </thead>
        <tbody>
            <?php $__empty_1 = true; $__currentLoopData = $runs; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $run): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                <tr>
                    <td><?php echo e($run['title'] ?: ('#' . $run['id'])); ?></td>
                    <td><span class="badge badge-info"><?php echo e($run['status'] ?: '—'); ?></span></td>
                    <td><?php echo e($run['conclusion'] ?: '—'); ?></td>
                    <td>
                        <?php echo e($run['created_at'] ? \Illuminate\Support\Carbon::parse($run['created_at'])->format('d.m.Y H:i') : '—'); ?>

                    </td>
                    <td>
                        <?php if($run['url']): ?>
                            <a href="<?php echo e($run['url']); ?>" target="_blank" rel="noopener">Открыть</a>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                <tr>
                    <td colspan="5" style="text-align: center; padding: 2rem; color: #6B778C;">
                        Запусков пока нет (или GitHub не настроен)
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('admin.layout', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /var/www/html/resources/views/admin/client-builds/index.blade.php ENDPATH**/ ?>