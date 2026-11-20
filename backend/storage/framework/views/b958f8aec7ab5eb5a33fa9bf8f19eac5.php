<?php $__env->startSection('title', 'Настройки уведомлений'); ?>

<?php $__env->startSection('content'); ?>
<div class="form-card" style="max-width: 700px;">
    <h2>🔔 Настройки уведомлений</h2>

    <form method="POST" action="<?php echo e(route('admin.notifications.update')); ?>">
        <?php echo csrf_field(); ?>

        <div class="form-group">
            <label>
                <input type="checkbox" name="notifications_digest_enabled" value="1"
                       <?php if(($settings['notifications_digest_enabled'] ?? '0') === '1'): echo 'checked'; endif; ?>>
                Включить email-дайджесты
            </label>
            <p style="color: #6B778C; font-size: 0.8125rem; margin: 0.35rem 0 0;">
                Пользователи выбирают частоту (ежедневно/еженедельно) в своём профиле.
            </p>
        </div>

        <div class="form-group">
            <label>Email отправителя</label>
            <input type="email" name="mail_from_address" value="<?php echo e(old('mail_from_address', $settings['mail_from_address'])); ?>" placeholder="noreply@example.com">
        </div>

        <div class="form-group">
            <label>Имя отправителя</label>
            <input type="text" name="mail_from_name" value="<?php echo e(old('mail_from_name', $settings['mail_from_name'])); ?>">
        </div>

        <div class="form-group">
            <label>Хранить уведомления (дней)</label>
            <input type="number" name="notification_retention_days" min="1" max="3650"
                   value="<?php echo e(old('notification_retention_days', $settings['notification_retention_days'])); ?>">
        </div>

        <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
            <button type="submit" class="btn btn-primary">💾 Сохранить</button>
        </div>
    </form>
</div>

<div class="form-card" style="max-width: 700px; margin-top: 1.5rem;">
    <h2>✉️ Проверка отправки</h2>
    <p style="color: #6B778C; font-size: 0.875rem;">
        Отправит тестовый дайджест на ваш email (<?php echo e(auth()->user()->email); ?>).
    </p>
    <form method="POST" action="<?php echo e(route('admin.notifications.test')); ?>">
        <?php echo csrf_field(); ?>
        <button type="submit" class="btn btn-secondary">Отправить тестовый дайджест</button>
    </form>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('admin.layout', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /var/www/html/resources/views/admin/notifications/settings.blade.php ENDPATH**/ ?>