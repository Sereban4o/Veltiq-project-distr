<?php

/**
 * Conservative OPcache preload script (used only in the production image).
 *
 * The autoloader and a handful of hot framework classes are compiled once when
 * php-fpm starts. Keep this list small and free of application-specific code: a
 * broken preload prevents the worker pool from starting at all.
 */
require __DIR__ . '/../vendor/autoload.php';

$classes = [
    Illuminate\Foundation\Application::class,
    Illuminate\Container\Container::class,
    Illuminate\Events\Dispatcher::class,
    Illuminate\Routing\Router::class,
    Illuminate\Support\Arr::class,
    Illuminate\Support\Str::class,
    Illuminate\Support\Collection::class,
    Illuminate\Support\Carbon::class,
    Illuminate\Http\Request::class,
    Illuminate\Http\Response::class,
    Illuminate\Support\Facades\Facade::class,
];

foreach ($classes as $class) {
    class_exists($class);
}
