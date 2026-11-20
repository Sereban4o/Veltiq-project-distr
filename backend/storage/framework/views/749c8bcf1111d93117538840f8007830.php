<?php $__env->startSection('title', 'Аудит хранилища'); ?>

<?php $__env->startSection('content'); ?>
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
    <h1 style="color: #172B4D;">🗂️ Аудит файлового хранилища</h1>
    <a href="<?php echo e(route('admin.storage-audit.export', request()->query())); ?>" class="btn btn-secondary btn-sm">⬇️ Экспорт CSV</a>
</div>

<form method="GET" class="filters" style="flex-wrap: wrap;">
    <select name="user_id">
        <option value="">Все пользователи</option>
        <?php $__currentLoopData = $users; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $user): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <option value="<?php echo e($user->id); ?>" <?php if((int) request('user_id') === $user->id): echo 'selected'; endif; ?>><?php echo e($user->full_name); ?></option>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    </select>

    <select name="action">
        <option value="">Все действия</option>
        <?php $__currentLoopData = $actions; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $action): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <option value="<?php echo e($action); ?>" <?php if(request('action') === $action): echo 'selected'; endif; ?>><?php echo e($action); ?></option>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    </select>

    <input type="number" name="status" placeholder="HTTP-статус" value="<?php echo e(request('status')); ?>">

    <input type="date" name="date_from" value="<?php echo e(request('date_from')); ?>">
    <input type="date" name="date_to" value="<?php echo e(request('date_to')); ?>">

    <input type="text" name="q" placeholder="Путь содержит..." value="<?php echo e(request('q')); ?>">

    <button type="submit" class="btn btn-primary btn-sm">🔍 Найти</button>
    <a href="<?php echo e(route('admin.storage-audit.index')); ?>" class="btn btn-secondary btn-sm">✕ Сбросить</a>
</form>

<div class="data-table">
    <table>
        <thead>
            <tr>
                <th>Дата</th>
                <th>Пользователь</th>
                <th>Действие</th>
                <th>Путь</th>
                <th>Статус</th>
                <th>IP</th>
            </tr>
        </thead>
        <tbody>
            <?php $__empty_1 = true; $__currentLoopData = $logs; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $log): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                <tr>
                    <td><?php echo e($log->created_at?->format('d.m.Y H:i:s')); ?></td>
                    <td><?php echo e($log->user?->full_name ?? '—'); ?></td>
                    <td><span class="badge badge-info"><?php echo e($log->action); ?></span></td>
                    <td style="max-width: 420px; word-break: break-all;"><?php echo e($log->path); ?></td>
                    <td><?php echo e($log->status ?? '—'); ?></td>
                    <td><?php echo e($log->ip ?? '—'); ?></td>
                </tr>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                <tr>
                    <td colspan="6" style="text-align: center; padding: 2rem; color: #6B778C;">Записей не найдено</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="pagination"><?php echo e($logs->links()); ?></div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('admin.layout', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /var/www/html/resources/views/admin/storage-audit/index.blade.php ENDPATH**/ ?>