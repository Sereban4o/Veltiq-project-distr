<?php $__env->startSection('title', 'Доп. поля'); ?>

<?php $__env->startSection('content'); ?>
<h1 style="margin-bottom: 1.5rem; color: #172B4D;">➕ Дополнительные поля задач</h1>

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
    <h2>Новое поле</h2>
    <p style="color: #6B778C; font-size: 0.875rem; margin-bottom: 1.25rem;">
        Ключ — латиница/цифры/подчёркивание (используется в API и в данных задачи). Для типа
        «список» перечислите варианты по одному в строке. Значения хранятся в задаче и не влияют
        на другие поля.
    </p>
    <form method="POST" action="<?php echo e(route('admin.custom-fields.store')); ?>">
        <?php echo csrf_field(); ?>
        <div style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-end;">
            <div class="form-group" style="margin-bottom: 0; min-width: 160px;">
                <label>Ключ</label>
                <input type="text" name="key" value="<?php echo e(old('key')); ?>" placeholder="team_code" required>
            </div>
            <div class="form-group" style="margin-bottom: 0; min-width: 200px;">
                <label>Название</label>
                <input type="text" name="label" value="<?php echo e(old('label')); ?>" placeholder="Код команды">
            </div>
            <div class="form-group" style="margin-bottom: 0; min-width: 150px;">
                <label>Тип</label>
                <select name="type">
                    <?php $__currentLoopData = $types; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $type): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <option value="<?php echo e($type); ?>" <?php if(old('type') === $type): echo 'selected'; endif; ?>><?php echo e($type); ?></option>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </select>
            </div>
            <div class="form-group" style="margin-bottom: 0; min-width: 120px;">
                <label>Порядок</label>
                <input type="number" name="position" value="<?php echo e(old('position', 0)); ?>">
            </div>
            <div class="form-group" style="margin-bottom: 0;">
                <label style="display: flex; align-items: center; gap: 0.4rem;">
                    <input type="checkbox" name="required" value="1" <?php if(old('required')): echo 'checked'; endif; ?>>
                    Обязательное
                </label>
            </div>
        </div>

        <div class="form-group" style="margin-top: 1rem; max-width: 420px;">
            <label>Варианты (только для типа «список»)</label>
            <textarea name="options" rows="3" style="font-family: monospace; font-size: 0.8125rem;"><?php echo e(old('options')); ?></textarea>
        </div>

        <button type="submit" class="btn btn-primary">➕ Добавить</button>
    </form>
</div>

<div class="data-table">
    <table>
        <thead>
            <tr>
                <th>Ключ</th>
                <th>Название</th>
                <th>Тип</th>
                <th>Обяз.</th>
                <th>Порядок</th>
                <th>Статус</th>
                <th>Действия</th>
            </tr>
        </thead>
        <tbody>
            <?php $__empty_1 = true; $__currentLoopData = $fields; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $field): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                <tr>
                    <td style="font-family: monospace; font-size: 0.8125rem;"><?php echo e($field->key); ?></td>
                    <td><?php echo e($field->label); ?></td>
                    <td><span class="badge badge-info"><?php echo e($field->type); ?></span></td>
                    <td><?php echo e($field->required ? 'да' : '—'); ?></td>
                    <td><?php echo e($field->position); ?></td>
                    <td>
                        <?php if($field->is_active): ?>
                            <span class="badge badge-success">активно</span>
                        <?php else: ?>
                            <span class="badge badge-danger">выключено</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                            <form method="POST" action="<?php echo e(route('admin.custom-fields.update', $field)); ?>" style="display: inline;">
                                <?php echo csrf_field(); ?>
                                <?php echo method_field('PUT'); ?>
                                <input type="hidden" name="is_active" value="<?php echo e($field->is_active ? '0' : '1'); ?>">
                                <button type="submit" class="btn btn-secondary btn-sm">
                                    <?php echo e($field->is_active ? 'Выключить' : 'Включить'); ?>

                                </button>
                            </form>

                            <form method="POST" action="<?php echo e(route('admin.custom-fields.destroy', $field)); ?>" style="display: inline;"
                                  onsubmit="return confirm('Удалить поле <?php echo e($field->key); ?>?');">
                                <?php echo csrf_field(); ?>
                                <?php echo method_field('DELETE'); ?>
                                <button type="submit" class="btn btn-danger btn-sm">Удалить</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                <tr>
                    <td colspan="7" style="text-align: center; padding: 2rem; color: #6B778C;">Полей пока нет</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('admin.layout', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /var/www/html/resources/views/admin/custom-fields/index.blade.php ENDPATH**/ ?>