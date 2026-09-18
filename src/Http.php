<?php

declare(strict_types=1);

namespace App;

final class Http
{
    /** @param array<string, mixed> $payload */
    public static function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');

        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /**
     * 302 rather than 301: a permanent redirect gets cached by browsers and
     * the click never reaches the server again, which would freeze the stats.
     */
    public static function redirect(string $url, int $status = 302): void
    {
        // Belt and braces - the validator already stripped control characters.
        $url = str_replace(["\r", "\n"], '', $url);

        http_response_code($status);
        header('Location: ' . $url, true, $status);
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Referrer-Policy: strict-origin-when-cross-origin');
    }
}
