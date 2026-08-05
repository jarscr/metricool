<?php

spl_autoload_register(function (string $class): void {
    $prefix = 'Metricool\\';
    $baseDir = __DIR__ . '/src/';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relative) . '.php';

    if (is_readable($file)) {
        require $file;
    }
});

use Metricool\Config\Config;

Config::load();
