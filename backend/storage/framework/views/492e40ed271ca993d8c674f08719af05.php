<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title><?php echo e($page->title); ?></title>
    <style>
        @page {
            margin: 24mm 18mm 24mm 18mm;
        }

        * {
            font-family: "DejaVu Sans", sans-serif;
        }

        body {
            color: #1f2937;
            font-size: 11pt;
            line-height: 1.5;
        }

        h1, h2, h3, h4, h5, h6 {
            color: #111827;
            margin: 18px 0 8px;
            line-height: 1.25;
        }

        h1 { font-size: 22pt; }
        h2 { font-size: 16pt; }
        h3 { font-size: 13pt; }

        p { margin: 0 0 10px; }

        a { color: #2563eb; text-decoration: none; }

        img {
            max-width: 100%;
            height: auto;
        }

        pre, code {
            font-family: "DejaVu Sans Mono", monospace;
            background: #f3f4f6;
            font-size: 9.5pt;
        }

        pre {
            padding: 10px;
            border-radius: 4px;
            white-space: pre-wrap;
            word-wrap: break-word;
        }

        blockquote {
            border-left: 3px solid #d1d5db;
            margin: 10px 0;
            padding: 0 12px;
            color: #4b5563;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0;
        }

        th, td {
            border: 1px solid #d1d5db;
            padding: 6px 8px;
            text-align: left;
        }

        .meta {
            color: #6b7280;
            font-size: 9pt;
            margin-bottom: 6px;
        }

        .title {
            border-bottom: 2px solid #e5e7eb;
            padding-bottom: 8px;
            margin-bottom: 6px;
        }

        .labels {
            margin-bottom: 16px;
        }

        .label {
            display: inline-block;
            background: #eef2ff;
            color: #4338ca;
            border-radius: 3px;
            padding: 2px 7px;
            font-size: 8.5pt;
            margin-right: 4px;
        }

        .footer {
            position: fixed;
            bottom: -16mm;
            left: 0;
            right: 0;
            font-size: 8pt;
            color: #9ca3af;
            text-align: center;
        }
    </style>
</head>
<body>
    <h1 class="title"><?php echo e($page->title); ?></h1>

    <div class="meta">
        <?php if($page->space): ?>
            <?php echo e($page->space->name); ?>

        <?php endif; ?>
        <?php if($page->author): ?>
            &middot; <?php echo e($page->author->name); ?>

        <?php endif; ?>
        &middot; <?php echo e(optional($page->updated_at)->format('d.m.Y H:i')); ?>

    </div>

    <?php if(!empty($page->labels)): ?>
        <div class="labels">
            <?php $__currentLoopData = $page->labels; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $label): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <span class="label"><?php echo e($label); ?></span>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </div>
    <?php endif; ?>

    <div class="content">
        <?php echo $body; ?>

    </div>

    <div class="footer">
        Veltiq &middot; <?php echo e($generatedAt->format('d.m.Y H:i')); ?>

    </div>
</body>
</html>
<?php /**PATH /var/www/html/resources/views/kb/export-pdf.blade.php ENDPATH**/ ?>