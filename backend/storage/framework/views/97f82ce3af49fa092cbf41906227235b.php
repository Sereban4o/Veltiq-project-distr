<?php $__env->startSection('title', 'Виджеты'); ?>

<?php $__env->startSection('content'); ?>
<h1 style="margin-bottom: 1.5rem; color: #172B4D;">🧩 Виджеты (встраиваемые панели)</h1>

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
    <h2>Настройка</h2>
    <p style="color: #6B778C; font-size: 0.875rem; margin-bottom: 1.25rem;">
        По одному виджету на строку: <code>Название | https://адрес | высота</code>. Высота необязательна
        (по умолчанию 480 px, допустимо 240–2000). Разрешены только <code>http</code>/<code>https</code>;
        виджет отображается в песочнице iframe и не имеет доступа к приложению.
    </p>

    <form method="POST" action="<?php echo e(route('admin.widgets.update')); ?>">
        <?php echo csrf_field(); ?>
        <?php echo method_field('PUT'); ?>

        <div class="form-group">
            <label>Виджеты</label>
            <textarea name="widgets" rows="10" style="font-family: monospace; font-size: 0.8125rem;"
                      placeholder="Grafana | https://grafana.example.com/d/abc?kiosk | 600"><?php echo e(old('widgets', $textarea)); ?></textarea>
        </div>

        <button type="submit" class="btn btn-primary">💾 Сохранить</button>
    </form>
</div>

<div class="form-card" style="max-width: 900px; margin-top: 1.5rem;">
    <h2>Активные виджеты</h2>
    <?php $__empty_1 = true; $__currentLoopData = $widgets; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $widget): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
        <div style="display: flex; justify-content: space-between; gap: 1rem; padding: 0.6rem 0; border-bottom: 1px solid #EBECF0;">
            <div style="min-width: 0;">
                <strong><?php echo e($widget['title']); ?></strong>
                <div style="font-family: monospace; font-size: 0.8125rem; color: #6B778C; overflow-wrap: anywhere;">
                    <?php echo e($widget['url']); ?>

                </div>
            </div>
            <span class="badge badge-info"><?php echo e($widget['height']); ?> px</span>
        </div>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
        <p style="color: #6B778C;">Виджеты не настроены — пункт меню на клиенте скрыт.</p>
    <?php endif; ?>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('admin.layout', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /var/www/html/resources/views/admin/widgets/index.blade.php ENDPATH**/ ?>