<?php

declare(strict_types=1);

namespace App;

use RuntimeException;
use Throwable;

/**
 * Renders a plain PHP template inside the shared layout.
 */
final class View
{
    public static function render(string $template, array $data = [], int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');

        echo self::capture($template, $data);
    }

    public static function capture(string $template, array $data = []): string
    {
        $file = dirname(__DIR__) . '/views/' . $template . '.php';

        if (!is_file($file)) {
            throw new RuntimeException("View not found: {$template}");
        }

        $content = self::buffer($file, $data);

        return self::buffer(
            dirname(__DIR__) . '/views/layout.php',
            ['content' => $content] + $data
        );
    }

    private static function buffer(string $file, array $data): string
    {
        extract($data, EXTR_SKIP);

        ob_start();

        try {
            require $file;
        } catch (Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        return (string) ob_get_clean();
    }

    /** Escape for HTML output. Short name because templates use it constantly. */
    public static function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
