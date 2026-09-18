<?php

declare(strict_types=1);

namespace App;

/**
 * Produces short codes and validates user-supplied aliases.
 *
 * Codes are drawn at random rather than derived from the row id, so the
 * number of links in the database is not guessable from a short URL.
 * Look-alike characters (0/O, 1/l/I) are left out so codes survive being
 * read aloud or copied off a screen.
 */
final class CodeGenerator
{
    public const ALPHABET = '23456789abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';

    public const ALIAS_PATTERN = '/^[A-Za-z0-9_-]{3,32}$/';

    /** Paths the router owns; they can never be handed out as aliases. */
    public const RESERVED = [
        'api', 'assets', 'admin', 'login', 'logout', 'stats', 'health',
        'favicon.ico', 'robots.txt', 'sitemap.xml', 'index.php',
    ];

    public function __construct(private readonly int $length = 7)
    {
    }

    public function generate(): string
    {
        $alphabet = self::ALPHABET;
        $max = strlen($alphabet) - 1;
        $length = max(3, $this->length);
        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= $alphabet[random_int(0, $max)];
        }

        return $code;
    }

    public function isValidAlias(string $alias): bool
    {
        return preg_match(self::ALIAS_PATTERN, $alias) === 1;
    }

    public function isReserved(string $alias): bool
    {
        return in_array(strtolower($alias), self::RESERVED, true);
    }
}
