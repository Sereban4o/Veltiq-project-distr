<?php $__env->startSection('title', 'Релизы приложений'); ?>

<?php $__env->startSection('content'); ?>
<h1 style="margin-bottom: 1.5rem; color: #172B4D;">🚀 Релизы приложений</h1>

<?php if($errors->any()): ?>
    <div class="alert alert-error">
        <ul style="margin: 0; padding-left: 1.25rem;">
            <?php $__currentLoopData = $errors->all(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $error): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <li><?php echo e($error); ?></li>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </ul>
    </div>
<?php endif; ?>

<div class="form-card" style="max-width: 100%; margin-bottom: 2rem;">
    <h2>Загрузить новый релиз</h2>
    <form method="POST" action="<?php echo e(route('admin.releases.store')); ?>" enctype="multipart/form-data">
        <?php echo csrf_field(); ?>
        <div style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-end;">
            <div class="form-group" style="margin-bottom: 0; min-width: 160px;">
                <label>Платформа</label>
                <select name="platform" required>
                    <?php $__currentLoopData = $platforms; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $platform): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <option value="<?php echo e($platform); ?>" <?php echo e(old('platform') === $platform ? 'selected' : ''); ?>>
                            <?php echo e(ucfirst($platform)); ?>

                        </option>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </select>
            </div>

            <div class="form-group" style="margin-bottom: 0; min-width: 170px;">
                <label>Версия (напр. 1.0.2)</label>
                <input type="text" name="version" value="<?php echo e(old('version')); ?>" placeholder="1.0.2" required>
            </div>

            <div class="form-group" style="margin-bottom: 0; min-width: 130px;">
                <label>Version code</label>
                <input type="number" name="versionCode" min="1" value="<?php echo e(old('versionCode')); ?>" required>
            </div>

            <div class="form-group" style="margin-bottom: 0; flex: 1; min-width: 240px;">
                <label>Описание изменений</label>
                <input type="text" name="notes" value="<?php echo e(old('notes')); ?>" placeholder="Что нового">
            </div>

            <div class="form-group" style="margin-bottom: 0; min-width: 240px;">
                <label>Файл релиза</label>
                <input type="file" name="file" required>
            </div>

            <div class="form-group" style="margin-bottom: 0;">
                <button type="submit" class="btn btn-primary">⬆️ Загрузить</button>
            </div>
        </div>
        <p style="margin-top: 1rem; color: #6B778C; font-size: 0.8125rem;">
            Каталог релизов: <code><?php echo e($path); ?></code>. Разрешённые типы: APK/AAB (Android), MSI/EXE/ZIP (Windows).
        </p>
    </form>
</div>

<div class="data-table">
    <table>
        <thead>
            <tr>
                <th>Платформа</th>
                <th>Версия</th>
                <th>Version code</th>
                <th>Файл</th>
                <th>SHA-256</th>
                <th>Загружен</th>
                <th>Действия</th>
            </tr>
        </thead>
        <tbody>
            <?php $__empty_1 = true; $__currentLoopData = $releases; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $release): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
            <tr>
                <td><span class="badge badge-info"><?php echo e(ucfirst($release['platform'] ?? '')); ?></span></td>
                <td><strong><?php echo e($release['version'] ?? '—'); ?></strong></td>
                <td><?php echo e($release['versionCode'] ?? '—'); ?></td>
                <td style="font-family: monospace; font-size: 0.8125rem;"><?php echo e($release['path'] ?? '—'); ?></td>
                <td style="font-family: monospace; font-size: 0.8125rem;">
                    <?php echo e(!empty($release['sha256']) ? substr($release['sha256'], 0, 12) . '…' : '—'); ?>

                </td>
                <td>
                    <?php echo e(!empty($release['uploaded_at']) ? \Illuminate\Support\Carbon::parse($release['uploaded_at'])->format('d.m.Y H:i') : '—'); ?>

                </td>
                <td>
                    <form method="POST" action="<?php echo e(route('admin.releases.delete', $release['platform'])); ?>"
                          style="display: inline;" onsubmit="return confirm('Удалить релиз <?php echo e($release['platform']); ?>?');">
                        <?php echo csrf_field(); ?>
                        <?php echo method_field('DELETE'); ?>
                        <button type="submit" class="btn btn-danger btn-sm">🗑️ Удалить</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
            <tr>
                <td colspan="7" style="text-align: center; padding: 2rem; color: #6B778C;">Релизов пока нет</td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('admin.layout', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /var/www/html/resources/views/admin/releases/index.blade.php ENDPATH**/ ?>