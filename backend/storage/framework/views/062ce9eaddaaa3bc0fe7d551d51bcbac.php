<?php $__env->startSection('title', 'Новый скин'); ?>

<?php $__env->startSection('content'); ?>
<h1 style="margin-bottom: 1.5rem; color: var(--text);">🎨 Новый скин</h1>

<?php if($errors->any()): ?>
    <div class="alert alert-error">
        <ul style="margin: 0; padding-left: 1.25rem;">
            <?php $__currentLoopData = $errors->all(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $error): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <li><?php echo e($error); ?></li>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </ul>
    </div>
<?php endif; ?>

<form method="POST" action="<?php echo e(route('admin.skins.store')); ?>" enctype="multipart/form-data">
    <?php echo csrf_field(); ?>

    <?php echo $__env->make('admin.skins._form', ['config' => $config, 'skin' => null], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>

    <div class="skin-actions">
        <label>
            <input type="checkbox" name="activate" value="1" checked>
            Применить сразу
        </label>
        <button type="submit" class="btn btn-primary">💾 Сохранить скин</button>
        <a href="<?php echo e(route('admin.skins.index')); ?>" class="btn btn-secondary">Отмена</a>
    </div>
</form>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('admin.layout', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /var/www/html/resources/views/admin/skins/create.blade.php ENDPATH**/ ?>