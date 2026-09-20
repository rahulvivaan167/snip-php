<?php

declare(strict_types=1);

namespace App;

/**
 * Thin read-only wrapper over the superglobals.
 *
 * Proxy headers are only believed when trust_proxy is switched on in the
 * config, because anyone can send an X-Forwarded-For.
 */
final class Request
{
    public static function method(): string
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    /** Request path with any query string and install sub-directory removed. */
    public static function path(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?? '/');
        $base = self::basePath();

        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }

        $path = '/' . ltrim(rawurldecode($path), '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    /**
     * Sub-directory the app is installed under, e.g. "/links" or "".
     *
     * Two layouts have to work: the document root pointing straight at
     * public/, and a rewrite that forwards requests into public/ without it
     * appearing in the URL. The request path decides which one is in play.
     */
    public static function basePath(): string
    {
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php');
        $directory = rtrim(str_replace('\\', '/', dirname($script)), '/');

        if ($directory === '' || $directory === '.') {
            return '';
        }

        $uri = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');

        if ($uri === $directory || str_starts_with($uri, $directory . '/')) {
            return $directory;
        }

        if (str_ends_with($directory, '/public')) {
            $parent = substr($directory, 0, -strlen('/public'));

            if ($parent === '' || $uri === $parent || str_starts_with($uri, $parent . '/')) {
                return $parent;
            }
        }

        return '';
    }

    public static function baseUrl(): string
    {
        $configured = Config::string('base_url');

        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

        return self::scheme() . '://' . $host . self::basePath();
    }

    public static function scheme(): string
    {
        if (Config::bool('trust_proxy') && isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            return strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https' ? 'https' : 'http';
        }

        $https = (string) ($_SERVER['HTTPS'] ?? '');

        return ($https !== '' && strtolower($https) !== 'off') ? 'https' : 'http';
    }

    public static function ip(): string
    {
        if (Config::bool('trust_proxy') && isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $forwarded = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']);
            $candidate = trim($forwarded[0]);

            if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                return $candidate;
            }
        }

        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    /**
     * Visitors are counted by a keyed hash of their address, never the
     * address itself, so the click log holds no raw personal data.
     */
    public static function ipHash(): string
    {
        return hash_hmac('sha256', self::ip(), Config::string('hash_key', 'snip'));
    }

    public static function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $_SERVER[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function wantsJson(): bool
    {
        $accept = self::header('Accept') ?? '';
        $contentType = self::header('Content-Type') ?? '';

        return str_contains($accept, 'application/json') || str_contains($contentType, 'application/json');
    }

    /** @return array<string, mixed> */
    public static function jsonBody(): array
    {
        $raw = (string) file_get_contents('php://input');

        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    public static function post(string $key): ?string
    {
        $value = $_POST[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
