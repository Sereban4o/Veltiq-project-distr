<?php $__env->startSection('title', 'Скин: ' . $skin->name); ?>

<?php $__env->startSection('content'); ?>
<div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1.5rem; flex-wrap: wrap;">
    <h1 style="color: var(--text); margin: 0;">🎨 <?php echo e($skin->name); ?></h1>
    <?php if($skin->is_active): ?>
        <span class="badge badge-success">Активен</span>
    <?php else: ?>
        <span class="badge badge-info">Не активен</span>
    <?php endif; ?>
    <a href="<?php echo e(route('admin.skins.index')); ?>" class="btn btn-sm btn-secondary" style="margin-left: auto;">← Все скины</a>
</div>

<?php if($errors->any()): ?>
    <div class="alert alert-error">
        <ul style="margin: 0; padding-left: 1.25rem;">
            <?php $__currentLoopData = $errors->all(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $error): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <li><?php echo e($error); ?></li>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </ul>
    </div>
<?php endif; ?>

<form method="POST" action="<?php echo e(route('admin.skins.update', $skin)); ?>" enctype="multipart/form-data">
    <?php echo csrf_field(); ?>
    <?php echo method_field('PUT'); ?>

    <?php echo $__env->make('admin.skins._form', ['config' => $config, 'skin' => $skin], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>

    <div class="skin-actions">
        <button type="submit" class="btn btn-primary">💾 Сохранить изменения</button>
        <a href="<?php echo e(route('admin.skins.index')); ?>" class="btn btn-secondary">Отмена</a>
    </div>
</form>

<div class="form-card" style="max-width: 100%; margin-top: 1.5rem;">
    <h2 style="margin-bottom: 1rem; color: var(--text); font-size: 1.05rem;">Действия</h2>
    <div class="skin-actions" style="margin-top: 0;">
        <?php if (! ($skin->is_active)): ?>
            <form method="POST" action="<?php echo e(route('admin.skins.apply', $skin)); ?>">
                <?php echo csrf_field(); ?>
                <button type="submit" class="btn btn-primary">✅ Применить скин</button>
            </form>
        <?php endif; ?>

        <form method="POST" action="<?php echo e(route('admin.skins.duplicate', $skin)); ?>">
            <?php echo csrf_field(); ?>
            <button type="submit" class="btn btn-secondary">📄 Дублировать</button>
        </form>

        <?php if (! ($skin->is_active)): ?>
            <form method="POST" action="<?php echo e(route('admin.skins.destroy', $skin)); ?>"
                  onsubmit="return confirm('Удалить скин?');">
                <?php echo csrf_field(); ?>
                <?php echo method_field('DELETE'); ?>
                <button type="submit" class="btn btn-danger">🗑️ Удалить</button>
            </form>
        <?php endif; ?>
    </div>
    <?php if($skin->is_active): ?>
        <p class="skin-hint" style="margin: 0.75rem 0 0;">
            Активный скин нельзя удалить — сначала примените другой скин.
        </p>
    <?php endif; ?>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('admin.layout', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /var/www/html/resources/views/admin/skins/edit.blade.php ENDPATH**/ ?>