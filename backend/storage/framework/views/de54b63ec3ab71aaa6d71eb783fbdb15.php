<?php $__env->startSection('title', 'Главная'); ?>

<?php $__env->startSection('content'); ?>
<h1 style="margin-bottom: 1.5rem; color: #172B4D;">Обзор системы</h1>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-value"><?php echo e($stats['total_users']); ?></div>
        <div class="stat-label">Всего пользователей</div>
    </div>
    <div class="stat-card">
        <div class="stat-value" style="color: #00875A;"><?php echo e($stats['active_users']); ?></div>
        <div class="stat-label">Активных</div>
    </div>
    <div class="stat-card">
        <div class="stat-value" style="color: #DE350B;"><?php echo e($stats['suspended_users']); ?></div>
        <div class="stat-label">Заблокированных</div>
    </div>
    <div class="stat-card">
        <div class="stat-value" style="color: #FF9800;"><?php echo e($stats['total_issues']); ?></div>
        <div class="stat-label">Задач</div>
    </div>
    <div class="stat-card">
        <div class="stat-value" style="color: #6B778C;"><?php echo e($stats['total_spaces']); ?></div>
        <div class="stat-label">Пространств Knowledgebase</div>
    </div>
</div>

<div style="background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
    <h2 style="margin-bottom: 1rem; color: #172B4D;">Быстрые действия</h2>
    <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
        <a href="<?php echo e(route('admin.users.create')); ?>" class="btn btn-primary">➕ Добавить пользователя</a>
        <a href="<?php echo e(route('admin.users.index')); ?>" class="btn btn-secondary">👥 Управление пользователями</a>
        <a href="<?php echo e(route('admin.statuses.index')); ?>" class="btn btn-secondary">📋 Управление статусами</a>
        <a href="<?php echo e(route('admin.priorities.index')); ?>" class="btn btn-secondary">🎯 Управление приоритетами</a>
        <a href="<?php echo e(route('admin.roles.index')); ?>" class="btn btn-secondary">🔐 Управление ролями</a>
        <a href="<?php echo e(route('admin.releases.index')); ?>" class="btn btn-secondary">🚀 Релизы приложений</a>
    </div>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('admin.layout', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /var/www/html/resources/views/admin/dashboard.blade.php ENDPATH**/ ?>