<?php $__env->startSection('title', 'Роли'); ?>

<?php $__env->startSection('content'); ?>
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
    <h1 style="color: #172B4D;">Роли</h1>
    <a href="<?php echo e(route('admin.roles.create')); ?>" class="btn btn-primary">➕ Добавить роль</a>
</div>

<div class="filters">
    <input type="text" placeholder="Поиск по названию или описанию..." name="search" value="<?php echo e(request('search')); ?>" style="flex: 1; max-width: 300px;">
    <button type="submit" class="btn btn-primary btn-sm">🔍 Найти</button>
    <a href="<?php echo e(route('admin.roles.index')); ?>" class="btn btn-secondary btn-sm">✕ Сбросить</a>
</div>

<div class="data-table">
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Название</th>
                <th>Описание</th>
                <th>Пользователей</th>
                <th>Действия</th>
            </tr>
        </thead>
        <tbody>
            <?php $__empty_1 = true; $__currentLoopData = $roles; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $role): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
            <tr>
                <td><?php echo e($role->id); ?></td>
                <td><strong><?php echo e($role->name); ?></strong></td>
                <td><?php echo e($role->description ?? '-'); ?></td>
                <td>
                    <span class="badge badge-info"><?php echo e($role->users_count); ?></span>
                </td>
                <td>
                    <div style="display: flex; gap: 0.5rem;">
                        <a href="<?php echo e(route('admin.roles.edit', $role->id)); ?>" class="btn btn-secondary btn-sm">✏️</a>
                        <?php if($role->name !== 'admin' && $role->users_count == 0): ?>
                        <form method="POST" action="<?php echo e(route('admin.roles.delete', $role->id)); ?>" style="display: inline;"
                              onsubmit="return confirm('Вы уверены?')">
                            <input type="hidden" name="_token" value="<?php echo e(csrf_token()); ?>">
                            <input type="hidden" name="_method" value="DELETE">
                            <button type="submit" class="btn btn-danger btn-sm">🗑️</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
            <tr>
                <td colspan="5" style="text-align: center; padding: 2rem; color: #6B778C;">Роли не найдены</td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="pagination">
    <?php echo e($roles->links()); ?>

</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('admin.layout', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /var/www/html/resources/views/admin/roles/index.blade.php ENDPATH**/ ?>