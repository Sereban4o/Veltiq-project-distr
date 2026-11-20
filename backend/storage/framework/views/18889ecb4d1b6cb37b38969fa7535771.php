<?php $__env->startSection('title', 'Вебхуки'); ?>

<?php $__env->startSection('content'); ?>
<h1 style="margin-bottom: 1.5rem; color: #172B4D;">🔌 Вебхуки (интеграции)</h1>

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
    <h2>Новый вебхук</h2>
    <p style="color: #6B778C; font-size: 0.875rem; margin-bottom: 1.25rem;">
        События задачи отправляются POST-запросом с JSON. Если задан «секрет», запрос подписывается
        HMAC-SHA256 в заголовке <code>X-Veltiq-Signature</code> (значение <code>sha256=…</code>).
    </p>
    <form method="POST" action="<?php echo e(route('admin.webhooks.store')); ?>">
        <?php echo csrf_field(); ?>
        <div style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-end;">
            <div class="form-group" style="margin-bottom: 0; min-width: 180px;">
                <label>Название</label>
                <input type="text" name="name" value="<?php echo e(old('name')); ?>" placeholder="CRM sync" required>
            </div>
            <div class="form-group" style="margin-bottom: 0; min-width: 320px; flex: 1;">
                <label>URL (http/https)</label>
                <input type="text" name="url" value="<?php echo e(old('url')); ?>" placeholder="https://example.com/webhooks/veltiq" required>
            </div>
            <div class="form-group" style="margin-bottom: 0; min-width: 200px;">
                <label>Секрет (необязательно)</label>
                <input type="password" name="secret" value="" autocomplete="new-password">
            </div>
        </div>

        <div class="form-group" style="margin-top: 1rem;">
            <label>События</label>
            <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
                <?php $__currentLoopData = $events; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $event): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <label style="display: flex; align-items: center; gap: 0.35rem;">
                        <input type="checkbox" name="events[]" value="<?php echo e($event); ?>" <?php if(in_array($event, old('events', ['issue.created']), true)): echo 'checked'; endif; ?>>
                        <?php echo e($event); ?>

                    </label>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </div>
        </div>

        <div class="form-group">
            <label style="display: flex; align-items: center; gap: 0.4rem;">
                <input type="checkbox" name="is_active" value="1" <?php if(old('is_active', true)): echo 'checked'; endif; ?>>
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
            <?php $__empty_1 = true; $__currentLoopData = $webhooks; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $webhook): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                <tr>
                    <td><strong><?php echo e($webhook->name); ?></strong></td>
                    <td style="font-family: monospace; font-size: 0.8125rem;"><?php echo e($webhook->url); ?></td>
                    <td>
                        <?php $__currentLoopData = $webhook->events ?? []; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $event): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <span class="badge badge-info"><?php echo e($event); ?></span>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </td>
                    <td>
                        <?php if($webhook->is_active): ?>
                            <span class="badge badge-success">активен</span>
                        <?php else: ?>
                            <span class="badge badge-danger">выключен</span>
                        <?php endif; ?>
                        <?php if($webhook->last_status): ?>
                            <div style="font-size: 0.75rem; color: #6B778C; margin-top: 0.25rem;">
                                HTTP <?php echo e($webhook->last_status); ?>

                                <?php if($webhook->last_called_at): ?> · <?php echo e($webhook->last_called_at->format('d.m.Y H:i')); ?> <?php endif; ?>
                                <?php if($webhook->failures > 0): ?> · сбоев: <?php echo e($webhook->failures); ?> <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                            <form method="POST" action="<?php echo e(route('admin.webhooks.test', $webhook)); ?>" style="display: inline;">
                                <?php echo csrf_field(); ?>
                                <button type="submit" class="btn btn-secondary btn-sm">Проверить</button>
                            </form>

                            <form method="POST" action="<?php echo e(route('admin.webhooks.update', $webhook)); ?>" style="display: inline;">
                                <?php echo csrf_field(); ?>
                                <?php echo method_field('PUT'); ?>
                                <input type="hidden" name="name" value="<?php echo e($webhook->name); ?>">
                                <input type="hidden" name="url" value="<?php echo e($webhook->url); ?>">
                                <?php $__currentLoopData = $webhook->events ?? []; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $event): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                    <input type="hidden" name="events[]" value="<?php echo e($event); ?>">
                                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                                <input type="hidden" name="is_active" value="<?php echo e($webhook->is_active ? '0' : '1'); ?>">
                                <button type="submit" class="btn btn-secondary btn-sm">
                                    <?php echo e($webhook->is_active ? 'Выключить' : 'Включить'); ?>

                                </button>
                            </form>

                            <form method="POST" action="<?php echo e(route('admin.webhooks.destroy', $webhook)); ?>" style="display: inline;"
                                  onsubmit="return confirm('Удалить вебхук <?php echo e($webhook->name); ?>?');">
                                <?php echo csrf_field(); ?>
                                <?php echo method_field('DELETE'); ?>
                                <button type="submit" class="btn btn-danger btn-sm">Удалить</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                <tr>
                    <td colspan="5" style="text-align: center; padding: 2rem; color: #6B778C;">Вебхуков пока нет</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('admin.layout', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /var/www/html/resources/views/admin/webhooks/index.blade.php ENDPATH**/ ?>