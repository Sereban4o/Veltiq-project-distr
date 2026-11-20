<?php $__env->startSection('title', 'Журнал входов'); ?>

<?php $__env->startSection('content'); ?>
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
    <h1 style="color: #172B4D;">🔑 Журнал входов</h1>
    <a href="<?php echo e(route('admin.login-logs.export', request()->query())); ?>" class="btn btn-secondary btn-sm">⬇️ Экспорт CSV</a>
</div>

<form method="GET" class="filters" style="flex-wrap: wrap;">
    <select name="user_id">
        <option value="">Все пользователи</option>
        <?php $__currentLoopData = $users; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $user): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <option value="<?php echo e($user->id); ?>" <?php if((int) request('user_id') === $user->id): echo 'selected'; endif; ?>><?php echo e($user->full_name); ?></option>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    </select>

    <select name="event">
        <option value="">Все события</option>
        <?php $__currentLoopData = $events; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $event): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <option value="<?php echo e($event); ?>" <?php if(request('event') === $event): echo 'selected'; endif; ?>><?php echo e($event); ?></option>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    </select>

    <select name="channel">
        <option value="">Все каналы</option>
        <?php $__currentLoopData = $channels; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $channel): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <option value="<?php echo e($channel); ?>" <?php if(request('channel') === $channel): echo 'selected'; endif; ?>><?php echo e($channel); ?></option>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    </select>

    <select name="successful">
        <option value="">Успешность</option>
        <option value="1" <?php if(request('successful') === '1'): echo 'selected'; endif; ?>>Успешные</option>
        <option value="0" <?php if(request('successful') === '0'): echo 'selected'; endif; ?>>Неуспешные</option>
    </select>

    <input type="email" name="email" placeholder="Email" value="<?php echo e(request('email')); ?>">
    <input type="date" name="date_from" value="<?php echo e(request('date_from')); ?>">
    <input type="date" name="date_to" value="<?php echo e(request('date_to')); ?>">

    <button type="submit" class="btn btn-primary btn-sm">🔍 Найти</button>
    <a href="<?php echo e(route('admin.login-logs.index')); ?>" class="btn btn-secondary btn-sm">✕ Сбросить</a>
</form>

<div class="data-table">
    <table>
        <thead>
            <tr>
                <th>Дата</th>
                <th>Email</th>
                <th>Событие</th>
                <th>Канал</th>
                <th>Статус</th>
                <th>IP</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php $__empty_1 = true; $__currentLoopData = $logs; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $log): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                <?php
                    $badge = match ($log->event) {
                        'login' => 'badge-success',
                        'failed' => 'badge-danger',
                        default => 'badge-info',
                    };
                ?>
                <tr>
                    <td><?php echo e($log->created_at?->format('d.m.Y H:i:s')); ?></td>
                    <td><?php echo e($log->email ?? '—'); ?></td>
                    <td><span class="badge <?php echo e($badge); ?>"><?php echo e($log->event); ?></span></td>
                    <td><span class="badge badge-info"><?php echo e($log->channel); ?></span></td>
                    <td>
                        <?php if($log->successful): ?>
                            <span class="badge badge-success">OK</span>
                        <?php else: ?>
                            <span class="badge badge-danger"><?php echo e($log->reason ?? 'fail'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo e($log->ip_address ?? '—'); ?></td>
                    <td><a href="<?php echo e(route('admin.login-logs.show', $log->id)); ?>" class="btn btn-secondary btn-sm">👁️</a></td>
                </tr>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                <tr>
                    <td colspan="7" style="text-align: center; padding: 2rem; color: #6B778C;">Записей не найдено</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="pagination"><?php echo e($logs->links()); ?></div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('admin.layout', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /var/www/html/resources/views/admin/login-logs/index.blade.php ENDPATH**/ ?>