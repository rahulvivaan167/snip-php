<?php

declare(strict_types=1);

namespace App;

/**
 * A single shortened link, as stored in the `links` table.
 */
final class Link
{
    public function __construct(
        public readonly int $id,
        public readonly string $code,
        public readonly string $targetUrl,
        public readonly string $createdAt,
        public readonly ?string $expiresAt,
        public readonly int $clickCount,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['code'],
            (string) $row['target_url'],
            (string) $row['created_at'],
            isset($row['expires_at']) && $row['expires_at'] !== null ? (string) $row['expires_at'] : null,
            (int) ($row['click_count'] ?? 0),
        );
    }

    public function hasExpired(?string $now = null): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return strtotime($this->expiresAt) < strtotime($now ?? gmdate('Y-m-d H:i:s'));
    }

    /** Host of the destination, handy for showing users where they are headed. */
    public function targetHost(): string
    {
        return (string) (parse_url($this->targetUrl, PHP_URL_HOST) ?? $this->targetUrl);
    }
}
