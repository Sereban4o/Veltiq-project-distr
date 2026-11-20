<?php $__env->startSection('title', 'Брендинг и тема'); ?>

<?php $__env->startSection('content'); ?>
<h1 style="margin-bottom: 1.5rem; color: #172B4D;">🎨 Брендинг и тема</h1>

<div class="form-card" style="max-width: 900px; margin-bottom: 1.5rem;">
    <h2 style="font-size: 1.05rem; margin-bottom: 0.75rem;">Активный скин</h2>
    <p style="color: #6B778C; font-size: 0.875rem; margin: 0;">
        <?php if($activeSkin): ?>
            Сейчас применён скин <strong><?php echo e($activeSkin->name); ?></strong>.
            Изменения ниже перезаписывают его настройки у клиентов.
        <?php else: ?>
            Активный скин не выбран — клиенты используют настройки этой страницы.
        <?php endif; ?>
        <a href="<?php echo e(route('admin.skins.index')); ?>">Управлять скинами →</a>
    </p>
</div>

<?php if($errors->any()): ?>
    <div class="alert alert-error">
        <ul style="margin: 0; padding-left: 1.25rem;">
            <?php $__currentLoopData = $errors->all(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $error): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <li><?php echo e($error); ?></li>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </ul>
    </div>
<?php endif; ?>

<div class="form-card" style="max-width: 900px;">
    <h2>Внешний вид</h2>
    <p style="color: #6B778C; font-size: 0.875rem; margin-bottom: 1.25rem;">
        Применяется на веб-клиенте без пересборки (runtime). Недопустимые значения отбрасываются при сохранении:
        цвета — только hex, URL — относительные или http(s), <code>customCss</code> санитизируется.
    </p>

    <form method="POST" action="<?php echo e(route('admin.branding.update')); ?>">
        <?php echo csrf_field(); ?>
        <?php echo method_field('PUT'); ?>

        <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
            <div class="form-group" style="min-width: 220px; flex: 1;">
                <label>Название приложения</label>
                <input type="text" name="appName" value="<?php echo e(old('appName', $config['appName'])); ?>">
            </div>
            <div class="form-group" style="min-width: 160px;">
                <label>Идентификатор бренда</label>
                <input type="text" name="brand" value="<?php echo e(old('brand', $config['brand'])); ?>">
            </div>
            <div class="form-group" style="min-width: 140px;">
                <label>Тема по умолчанию</label>
                <select name="mode">
                    <?php $__currentLoopData = ['system' => 'Как в системе', 'light' => 'Светлая', 'dark' => 'Тёмная']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $value => $label): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <option value="<?php echo e($value); ?>" <?php if(old('mode', $config['theme']['palette']['mode']) === $value): echo 'selected'; endif; ?>><?php echo e($label); ?></option>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </select>
            </div>
        </div>

        <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
            <div class="form-group" style="min-width: 240px; flex: 1;">
                <label>Логотип (URL или /путь)</label>
                <input type="text" name="logo" value="<?php echo e(old('logo', $config['logo'])); ?>" placeholder="/veltiq-icon.svg">
            </div>
            <div class="form-group" style="min-width: 240px; flex: 1;">
                <label>Фавикон (URL или /путь)</label>
                <input type="text" name="favicon" value="<?php echo e(old('favicon', $config['favicon'])); ?>" placeholder="/favicon.svg">
            </div>
        </div>

        <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
            <div class="form-group" style="min-width: 160px;">
                <label>Основной цвет</label>
                <input type="color" name="primary" value="<?php echo e(old('primary', $config['theme']['palette']['primary'])); ?>" style="height: 42px; padding: 0.25rem;">
            </div>
            <div class="form-group" style="min-width: 160px;">
                <label>Дополнительный цвет</label>
                <input type="color" name="secondary" value="<?php echo e(old('secondary', $config['theme']['palette']['secondary'])); ?>" style="height: 42px; padding: 0.25rem;">
            </div>
            <div class="form-group" style="min-width: 120px;">
                <label>Радиус, px</label>
                <input type="number" name="borderRadius" min="0" max="32" value="<?php echo e(old('borderRadius', $config['theme']['shape']['borderRadius'])); ?>">
            </div>
            <div class="form-group" style="min-width: 260px; flex: 1;">
                <label>Шрифт</label>
                <input type="text" name="fontFamily" value="<?php echo e(old('fontFamily', $config['theme']['typography']['fontFamily'])); ?>" placeholder="Inter, Helvetica, Arial, sans-serif">
            </div>
        </div>

        <div class="form-group">
            <label>Custom CSS (санитизируется)</label>
            <textarea name="customCss" rows="8" style="font-family: monospace; font-size: 0.8125rem;" placeholder=":root { --brand-accent: #…; }"><?php echo e(old('customCss', $config['customCss'])); ?></textarea>
        </div>

        <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
            <div class="form-group" style="min-width: 240px; flex: 1;">
                <label>Порядок меню (ключи через запятую)</label>
                <input type="text" name="menu_order" value="<?php echo e(old('menu_order', implode(', ', $config['menu']['order']))); ?>" placeholder="board, issues, knowledgebase, chat">
            </div>
            <div class="form-group" style="min-width: 240px; flex: 1;">
                <label>Скрыть пункты меню (ключи через запятую)</label>
                <input type="text" name="menu_hidden" value="<?php echo e(old('menu_hidden', implode(', ', $config['menu']['hidden']))); ?>" placeholder="downloads, timesheet">
            </div>
        </div>
        <p style="color: #6B778C; font-size: 0.75rem; margin-bottom: 1rem;">
            Доступные ключи: board, timeline, automations, timesheet, calendar, crm, mail, files, assistant, downloads, knowledgebase.
        </p>

        <?php
            $labelsText = collect($config['i18n']['labels'] ?? [])
                ->map(fn ($value, $key) => $key . ' = ' . $value)
                ->implode("\n");
        ?>
        <div class="form-group">
            <label>Метки меню (ключ = текст, по строке)</label>
            <textarea name="labels" rows="4" style="font-family: monospace; font-size: 0.8125rem;" placeholder="board = Доска задач&#10;knowledgebase = База знаний"><?php echo e(old('labels', $labelsText)); ?></textarea>
        </div>

        <div style="display: flex; gap: 1rem;">
            <button type="submit" class="btn btn-primary">💾 Сохранить</button>
        </div>
    </form>
</div>

<div class="form-card" style="max-width: 900px; margin-top: 1.5rem;">
    <h2>Предпросмотр конфигурации</h2>
    <p style="color: #6B778C; font-size: 0.875rem; margin-bottom: 1rem;">
        Публичный эндпоинт: <code>GET /api/v1/branding</code>.
    </p>
    <pre style="background: #FAFBFC; border: 1px solid #DFE1E6; border-radius: 8px; padding: 1rem; overflow: auto; font-size: 0.8125rem;"><?php echo e(json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></pre>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('admin.layout', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /var/www/html/resources/views/admin/branding/index.blade.php ENDPATH**/ ?>