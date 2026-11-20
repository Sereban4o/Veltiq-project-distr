<?php $__env->startSection('title', 'Доступ к пространствам'); ?>

<?php $__env->startSection('content'); ?>
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
    <h1 style="color: #172B4D;">🔐 Доступ к пространствам</h1>
    <a href="<?php echo e(route('admin.access.projects')); ?>" class="btn btn-secondary btn-sm">← Перейти к проектам</a>
</div>

<?php echo $__env->make('admin.access._content', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('admin.layout', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /var/www/html/resources/views/admin/access/spaces.blade.php ENDPATH**/ ?>