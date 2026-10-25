<?php

declare(strict_types=1);

/** Lightweight PSR-4 autoloader; Composer can replace it without changing code. */
spl_autoload_register(static function (string $class): void {
    $prefix = 'MarketplaceSync\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $path = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require $path;
    }
});
