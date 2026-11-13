<?php

declare(strict_types=1);

// Resolve RuneCloud classes from their namespace-relative paths.
spl_autoload_register(function (string $class): void {
    $prefix = 'RuneCloud\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $path = __DIR__ . '/src/php/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($path)) {
        require $path;
    }
});
