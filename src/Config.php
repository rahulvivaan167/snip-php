<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * Reads the config array once and hands out values by dot path,
 * e.g. Config::get('db.driver').
 */
final class Config
{
    /** @var array<string, mixed> */
    private static array $values = [];

    private static bool $loaded = false;

    public static function load(string $file): void
    {
        if (!is_file($file)) {
            throw new RuntimeException("Config file not found: {$file}. Copy config.example.php to config.php.");
        }

        $values = require $file;

        if (!is_array($values)) {
            throw new RuntimeException("Config file {$file} must return an array.");
        }

        self::$values = $values;
        self::$loaded = true;
    }

    public static function get(string $path, mixed $default = null): mixed
    {
        if (!self::$loaded) {
            throw new RuntimeException('Config::load() must be called before reading values.');
        }

        $value = self::$values;

        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    public static function string(string $path, string $default = ''): string
    {
        $value = self::get($path, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    public static function int(string $path, int $default = 0): int
    {
        $value = self::get($path, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public static function bool(string $path, bool $default = false): bool
    {
        $value = self::get($path, $default);

        return is_bool($value) ? $value : $default;
    }

    /** Test helper: replace the loaded config wholesale. */
    public static function set(array $values): void
    {
        self::$values = $values;
        self::$loaded = true;
    }
}
