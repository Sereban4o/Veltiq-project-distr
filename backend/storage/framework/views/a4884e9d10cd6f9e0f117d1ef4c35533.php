<?php
    $scopeLabel = $scopeType === 'project' ? 'проекта' : 'пространства';
    $userNames = $users->mapWithKeys(fn ($u) => [$u->id => trim($u->first_name.' '.$u->last_name).' <'.$u->email.'>']);
?>

<div class="form-card" style="max-width: 1100px; margin-bottom: 1.5rem;">
    <h2>➕ Добавить правило</h2>
    <p style="color: #6B778C; margin-bottom: 1rem; font-size: 0.875rem;">
        Правило <strong>deny</strong> запрещает право даже если роль участника его разрешает.
        Правило <strong>allow</strong> выдаёт право дополнительно к роли участника.
        Запрет в конкретном объекте важнее разрешения на глобальном уровне.
    </p>

    <form method="POST" action="<?php echo e(route('admin.access.overrides.store')); ?>">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="scope_type" value="<?php echo e($scopeType); ?>">

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem;">
            <div class="form-group">
                <label>Объект</label>
                <select name="scope_id" required>
                    <?php $__currentLoopData = $scopes; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $scope): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <option value="<?php echo e($scope->id); ?>"><?php echo e($scope->name); ?> (<?php echo e($scope->key); ?>)</option>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </select>
            </div>

            <div class="form-group">
                <label>Кому</label>
                <select name="target" required>
                    <optgroup label="Пользователи">
                        <?php $__currentLoopData = $users; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $user): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <option value="user:<?php echo e($user->id); ?>"><?php echo e(trim($user->first_name.' '.$user->last_name)); ?> &lt;<?php echo e($user->email); ?>&gt;</option>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </optgroup>
                    <optgroup label="Роли">
                        <?php $__currentLoopData = $roles; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $role): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <option value="role:<?php echo e($role->name); ?>"><?php echo e($role->name); ?></option>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </optgroup>
                </select>
            </div>

            <div class="form-group">
                <label>Право</label>
                <select name="permission" required>
                    <?php $__currentLoopData = $catalogue; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $group): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <optgroup label="<?php echo e($group['label']); ?>">
                            <?php $__currentLoopData = $group['permissions']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $name => $label): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                <?php if(\App\Support\Permissions\PermissionCatalogue::allowsScope($name, $scopeType)): ?>
                                    <option value="<?php echo e($name); ?>"><?php echo e($label); ?> (<?php echo e($name); ?>)</option>
                                <?php endif; ?>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                        </optgroup>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </select>
            </div>

            <div class="form-group">
                <label>Эффект</label>
                <select name="effect" required>
                    <option value="allow">Разрешить (allow)</option>
                    <option value="deny">Запретить (deny)</option>
                </select>
            </div>
        </div>

        <button type="submit" class="btn btn-primary">💾 Сохранить правило</button>
    </form>
</div>

<div class="data-table">
    <table>
        <thead>
            <tr>
                <th>Объект</th>
                <th>Кому</th>
                <th>Право</th>
                <th>Эффект</th>
                <th>Создал</th>
                <th>Дата</th>
                <th>Действия</th>
            </tr>
        </thead>
        <tbody>
            <?php $__empty_1 = true; $__currentLoopData = $overrides; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $override): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                <tr>
                    <td>
                        <?php if($override->scope_id): ?>
                            <?php echo e($scopeNames[$override->scope_id] ?? ('#'.$override->scope_id)); ?>

                        <?php else: ?>
                            <em>Глобально</em>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if($override->target_type === 'user'): ?>
                            <?php echo e($userNames[(int) $override->target_value] ?? ('#'.$override->target_value)); ?>

                        <?php else: ?>
                            Роль: <strong><?php echo e($override->target_value); ?></strong>
                        <?php endif; ?>
                    </td>
                    <td>
                        <strong><?php echo e(\App\Support\Permissions\PermissionCatalogue::label($override->permission)); ?></strong>
                        <br><code style="font-size: 0.75rem; color: #6B778C;"><?php echo e($override->permission); ?></code>
                    </td>
                    <td>
                        <span class="badge <?php echo e($override->effect === 'deny' ? 'badge-danger' : 'badge-success'); ?>">
                            <?php echo e($override->effect); ?>

                        </span>
                    </td>
                    <td><?php echo e($override->creator?->full_name ?? '—'); ?></td>
                    <td><?php echo e($override->created_at?->format('d.m.Y H:i')); ?></td>
                    <td>
                        <form method="POST" action="<?php echo e(route('admin.access.overrides.destroy', $override->id)); ?>"
                              onsubmit="return confirm('Удалить правило?')">
                            <?php echo csrf_field(); ?>
                            <?php echo method_field('DELETE'); ?>
                            <button type="submit" class="btn btn-danger btn-sm">🗑️</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                <tr>
                    <td colspan="7" style="text-align: center; padding: 2rem; color: #6B778C;">
                        Правил для <?php echo e($scopeLabel === 'проекта' ? 'проектов' : 'пространств'); ?> пока нет
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php /**PATH /var/www/html/resources/views/admin/access/_content.blade.php ENDPATH**/ ?>