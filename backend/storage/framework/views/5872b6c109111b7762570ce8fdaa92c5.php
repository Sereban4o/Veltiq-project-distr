<?php $__env->startSection('title', 'Рассылка уведомлений'); ?>

<?php $__env->startSection('content'); ?>
<div class="form-card" style="max-width: 760px;">
    <h2>📨 Рассылка уведомлений</h2>
    <p style="color: #6B778C; font-size: 0.875rem;">
        Активных получателей сейчас: <strong><?php echo e($recipients); ?></strong>.
        Уведомление сохраняется в ленте у каждого получателя, отправляется в realtime и push
        (с учётом персональных настроек каналов).
    </p>

    <form method="POST" action="<?php echo e(route('admin.notifications.broadcast.send')); ?>">
        <?php echo csrf_field(); ?>

        <div class="form-group">
            <label>Текст уведомления</label>
            <textarea name="message" rows="4" required maxlength="1000"><?php echo e(old('message')); ?></textarea>
            <?php $__errorArgs = ['message'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                <p style="color: #DE350B; font-size: 0.8125rem; margin: 0.35rem 0 0;"><?php echo e($message); ?></p>
            <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
        </div>

        <div class="form-group" style="display: flex; gap: 1rem; flex-wrap: wrap;">
            <div style="flex: 1; min-width: 220px;">
                <label>Заголовок push</label>
                <input type="text" name="title" value="<?php echo e(old('title', 'Tasksbase')); ?>" maxlength="120">
            </div>
            <div style="flex: 1; min-width: 200px;">
                <label>Тип</label>
                <select name="type">
                    <option value="system" <?php if(old('type', 'system') === 'system'): echo 'selected'; endif; ?>>system</option>
                    <?php $__currentLoopData = $types; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $available): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <option value="<?php echo e($available); ?>" <?php if(old('type') === $available): echo 'selected'; endif; ?>><?php echo e($available); ?></option>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </select>
            </div>
            <div style="flex: 1; min-width: 200px;">
                <label>Роль</label>
                <select name="role">
                    <option value="">Все роли</option>
                    <?php $__currentLoopData = $roles; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $role): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <option value="<?php echo e($role); ?>" <?php if(old('role') === $role): echo 'selected'; endif; ?>><?php echo e($role); ?></option>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </select>
            </div>
        </div>

        <div class="form-group">
            <label>
                <input type="checkbox" name="include_inactive" value="1" <?php if(old('include_inactive')): echo 'checked'; endif; ?>>
                Включить пользователей не в статусе active
            </label>
        </div>

        <button type="submit" class="btn btn-primary">📨 Отправить</button>
    </form>
</div>

<div class="form-card" style="margin-top: 1.5rem;">
    <h2>🗂️ Очередь уведомлений</h2>

    <form method="GET" class="filters" style="flex-wrap: wrap;">
        <input type="text" name="q" value="<?php echo e($filters['q']); ?>" placeholder="Пользователь или тип">
        <select name="type">
            <option value="">Все типы</option>
            <?php $__currentLoopData = $types; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $available): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <option value="<?php echo e($available); ?>" <?php if($filters['type'] === $available): echo 'selected'; endif; ?>><?php echo e($available); ?></option>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </select>
        <button class="btn btn-primary btn-sm" type="submit">🔍 Найти</button>
        <a href="<?php echo e(route('admin.notifications.broadcast')); ?>" class="btn btn-secondary btn-sm">✕ Сбросить</a>
    </form>

    <div class="data-table">
        <table>
            <thead>
            <tr>
                <th>Дата</th>
                <th>Получатель</th>
                <th>Тип</th>
                <th>Текст</th>
                <th>Прочитано</th>
            </tr>
            </thead>
            <tbody>
            <?php $__empty_1 = true; $__currentLoopData = $notifications; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $notification): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                <?php ($data = (array) $notification->data); ?>
                <tr>
                    <td><?php echo e($notification->created_at?->format('d.m.Y H:i')); ?></td>
                    <td>
                        <?php echo e($notification->user?->full_name ?? '—'); ?><br>
                        <span style="color: #6B778C; font-size: 0.75rem;"><?php echo e($notification->user?->email); ?></span>
                    </td>
                    <td><span class="badge badge-info"><?php echo e($notification->type); ?></span></td>
                    <td><?php echo e(\Illuminate\Support\Str::limit((string) ($data['summary'] ?? $data['message'] ?? '—'), 160)); ?></td>
                    <td><?php echo e($notification->read ? '✓' : '—'); ?></td>
                </tr>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                <tr>
                    <td colspan="5" style="text-align: center; padding: 2rem; color: #6B778C;">Уведомлений нет</td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="pagination"><?php echo e($notifications->links()); ?></div>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('admin.layout', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /var/www/html/resources/views/admin/notifications/broadcast.blade.php ENDPATH**/ ?>