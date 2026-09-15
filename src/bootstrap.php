<?php

declare(strict_types=1);

/**
 * Single entry point for wiring the application together.
 * No Composer, no vendor directory - just a PSR-4 style autoloader.
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require $file;
    }
});

$root = dirname(__DIR__);

App\Config::load(
    is_file($root . '/config.php') ? $root . '/config.php' : $root . '/config.example.php'
);

date_default_timezone_set('UTC');

error_reporting(E_ALL);
ini_set('display_errors', App\Config::bool('debug') ? '1' : '0');

if (!function_exists('e')) {
    /**
     * Escape a value for HTML. Templates are plain PHP, so this is the one
     * helper they get - and everything printed in a view goes through it.
     */
    function e(?string $value): string
    {
        return App\View::e($value);
    }
}
