<?php

declare(strict_types=1);

namespace App;

/**
 * Per-session CSRF token for the shorten form.
 */
final class Csrf
{
    private const KEY = '_csrf';

    public static function token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (!isset($_SESSION[self::KEY]) || !is_string($_SESSION[self::KEY])) {
            $_SESSION[self::KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::KEY];
    }

    public static function check(?string $candidate): bool
    {
        if ($candidate === null || $candidate === '') {
            return false;
        }

        return hash_equals(self::token(), $candidate);
    }
}
