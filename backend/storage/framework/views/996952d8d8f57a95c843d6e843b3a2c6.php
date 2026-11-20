<?php $__env->startSection('title', 'Контрагенты'); ?>

<?php $__env->startSection('content'); ?>
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
    <h1 style="color: #172B4D; margin: 0;">🏢 Контрагенты (1С)</h1>
    <div style="display: flex; gap: 0.5rem; align-items: center;">
        <form method="POST" action="<?php echo e(route('admin.counterparties.sync')); ?>" style="display: inline;">
            <?php echo csrf_field(); ?>
            <button type="submit" class="btn btn-primary" <?php if(!$configured): echo 'disabled'; endif; ?>>🔄 Загрузить из 1С</button>
        </form>
    </div>
</div>

<?php if(!$configured): ?>
    <div class="alert alert-error">
        Сервис 1С не настроен. Задайте в окружении <code>ONE_C_COUNTERPARTIES_URL</code>,
        <code>ONE_C_COUNTERPARTIES_LOGIN</code> и <code>ONE_C_COUNTERPARTIES_PASSWORD</code>.
    </div>
<?php endif; ?>

<div style="display: flex; gap: 2rem; flex-wrap: wrap; margin-bottom: 1.5rem; font-size: 0.875rem; color: #42526E;">
    <div><strong>Связано с 1С:</strong> <?php echo e($linkedCount); ?></div>
    <div><strong>Помечено на удаление в 1С:</strong> <?php echo e($deletedCount); ?></div>
    <div>
        <strong>Последняя синхронизация:</strong>
        <?php echo e($syncedAt ? \Illuminate\Support\Carbon::parse($syncedAt)->format('d.m.Y H:i') : '—'); ?>

    </div>
</div>

<div class="form-card" style="max-width: 900px; margin-bottom: 1.5rem;">
    <h2>Подключение к сервису 1С</h2>
    <p style="color: #6B778C; font-size: 0.875rem; margin-top: 0;">
        Адрес SOAP-сервиса контрагентов и учётные данные публикации (аутентификация 1С:Предприятия).
        Пример: <code>http://server/base/ws/CounterpartiesExchange.1cws</code>. Пароль хранится зашифрованным.
    </p>
    <form method="POST" action="<?php echo e(route('admin.counterparties.settings')); ?>">
        <?php echo csrf_field(); ?>
        <div class="form-group">
            <label>Адрес сервиса</label>
            <input type="text" name="url" value="<?php echo e(old('url', $url)); ?>" placeholder="http://server/base/ws/CounterpartiesExchange.1cws">
        </div>
        <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
            <div class="form-group" style="flex: 1; min-width: 220px;">
                <label>Логин</label>
                <input type="text" name="login" value="<?php echo e(old('login', $login)); ?>" autocomplete="off">
            </div>
            <div class="form-group" style="flex: 1; min-width: 220px;">
                <label>Пароль</label>
                <input type="password" name="password" value="" autocomplete="new-password" placeholder="<?php echo e($password_set ? 'оставьте пустым, чтобы не менять' : 'пароль'); ?>">
                <?php if($password_set): ?>
                    <label style="display: flex; align-items: center; gap: 0.5rem; font-weight: 400; margin-top: 0.5rem;">
                        <input type="checkbox" name="clear_password" value="1"> Очистить сохранённый пароль
                    </label>
                <?php endif; ?>
            </div>
        </div>
        <div class="form-group">
            <label style="display: flex; align-items: center; gap: 0.5rem; font-weight: 400;">
                <input type="checkbox" name="verify_tls" value="1" <?php if(old('verify_tls', $verify)): echo 'checked'; endif; ?>> Проверять TLS-сертификат (снимите для self-signed)
            </label>
        </div>
        <button type="submit" class="btn btn-primary">💾 Сохранить настройки</button>
    </form>
</div>

<form method="GET" action="<?php echo e(route('admin.counterparties.index')); ?>" class="filters">
    <input type="text" name="search" placeholder="Поиск по наименованию или ИНН..." value="<?php echo e($search); ?>" style="flex: 1; max-width: 320px;">
    <button type="submit" class="btn btn-primary btn-sm">🔍 Найти</button>
    <a href="<?php echo e(route('admin.counterparties.index')); ?>" class="btn btn-secondary btn-sm">✕ Сбросить</a>
</form>

<div class="data-table">
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Наименование</th>
                <th>Полное наименование</th>
                <th>ИНН</th>
                <th>КПП</th>
                <th>Юр/Физ</th>
                <th>Телефон</th>
                <th>Email</th>
                <th>Код 1С</th>
                <th>Обновлено</th>
            </tr>
        </thead>
        <tbody>
            <?php $__empty_1 = true; $__currentLoopData = $companies; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $company): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
            <tr>
                <td><?php echo e($company->id); ?></td>
                <td>
                    <strong><?php echo e($company->name); ?></strong>
                    <?php if($company->ref_1c): ?>
                        <span class="badge badge-info">1С</span>
                    <?php endif; ?>
                    <?php if($company->deleted_in_1c): ?>
                        <span class="badge badge-danger">Удалён в 1С</span>
                    <?php endif; ?>
                </td>
                <td><?php echo e($company->full_name ?: '—'); ?></td>
                <td><?php echo e($company->inn ?: '—'); ?></td>
                <td><?php echo e($company->kpp ?: '—'); ?></td>
                <td><?php echo e($company->legal_physical_person ?: '—'); ?></td>
                <td><?php echo e($company->phone ?: '—'); ?></td>
                <td><?php echo e($company->email ?: '—'); ?></td>
                <td><?php echo e($company->code_1c ?: '—'); ?></td>
                <td><?php echo e($company->synced_at ? $company->synced_at->format('d.m.Y H:i') : '—'); ?></td>
            </tr>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
            <tr>
                <td colspan="10" style="text-align: center; padding: 2rem; color: #6B778C;">Контрагенты не найдены</td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="pagination">
    <?php echo e($companies->links()); ?>

</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('admin.layout', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /var/www/html/resources/views/admin/counterparties/index.blade.php ENDPATH**/ ?>