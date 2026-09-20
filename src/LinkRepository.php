<?php

declare(strict_types=1);

namespace App;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Every query in the application lives here. Nothing else touches PDO,
 * which keeps the SQL reviewable in one place and the rest of the code
 * free of database concerns.
 */
final class LinkRepository
{
    /** SQLSTATE for a unique / integrity constraint violation. */
    private const UNIQUE_VIOLATION = '23000';

    private const MAX_CODE_ATTEMPTS = 6;

    public function __construct(
        private readonly PDO $pdo,
        private readonly CodeGenerator $codes,
    ) {
    }

    /**
     * @throws ValidationException when a requested alias is unusable
     */
    public function create(
        string $targetUrl,
        ?string $alias = null,
        ?string $ipHash = null,
        ?string $expiresAt = null,
    ): Link {
        $now = gmdate('Y-m-d H:i:s');

        if ($alias !== null && $alias !== '') {
            if (!$this->codes->isValidAlias($alias)) {
                throw new ValidationException('Custom links can use 3-32 letters, numbers, hyphens or underscores.');
            }

            if ($this->codes->isReserved($alias)) {
                throw new ValidationException('That name is reserved. Try another one.');
            }

            try {
                $id = $this->insert($alias, $targetUrl, $now, $expiresAt, $ipHash);
            } catch (PDOException $e) {
                if ($this->isUniqueViolation($e)) {
                    throw new ValidationException('That custom link is already taken.');
                }

                throw $e;
            }

            return new Link($id, $alias, $targetUrl, $now, $expiresAt, 0);
        }

        // Random codes collide rarely; when they do, just draw again.
        for ($attempt = 0; $attempt < self::MAX_CODE_ATTEMPTS; $attempt++) {
            $code = $this->codes->generate();

            try {
                $id = $this->insert($code, $targetUrl, $now, $expiresAt, $ipHash);
            } catch (PDOException $e) {
                if ($this->isUniqueViolation($e)) {
                    continue;
                }

                throw $e;
            }

            return new Link($id, $code, $targetUrl, $now, $expiresAt, 0);
        }

        throw new RuntimeException('Could not allocate a free short code. Increase code_length in config.php.');
    }

    public function findByCode(string $code): ?Link
    {
        $statement = $this->pdo->prepare(
            'SELECT id, code, target_url, created_at, expires_at, click_count
               FROM links
              WHERE code = :code
              LIMIT 1'
        );
        $statement->execute(['code' => $code]);

        $row = $statement->fetch();

        return is_array($row) ? Link::fromRow($row) : null;
    }

    /**
     * Writes the click and bumps the denormalised counter together, so the
     * number on the stats page always matches the rows behind it.
     */
    public function recordClick(Link $link, ?string $ipHash, ?string $referer, ?string $userAgent): void
    {
        $this->pdo->beginTransaction();

        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO clicks (link_id, clicked_at, ip_hash, referer, user_agent)
                      VALUES (:link_id, :clicked_at, :ip_hash, :referer, :user_agent)'
            );
            $statement->execute([
                'link_id' => $link->id,
                'clicked_at' => gmdate('Y-m-d H:i:s'),
                'ip_hash' => $ipHash,
                'referer' => $referer !== null ? substr($referer, 0, 512) : null,
                'user_agent' => $userAgent !== null ? substr($userAgent, 0, 512) : null,
            ]);

            $this->pdo
                ->prepare('UPDATE links SET click_count = click_count + 1 WHERE id = :id')
                ->execute(['id' => $link->id]);

            $this->pdo->commit();
        } catch (PDOException $e) {
            $this->pdo->rollBack();

            throw $e;
        }
    }

    /**
     * @return array{total: int, unique: int, last_click: ?string}
     */
    public function summary(Link $link): array
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) AS total,
                    COUNT(DISTINCT ip_hash) AS unique_visitors,
                    MAX(clicked_at) AS last_click
               FROM clicks
              WHERE link_id = :link_id'
        );
        $statement->execute(['link_id' => $link->id]);

        $row = $statement->fetch() ?: [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'unique' => (int) ($row['unique_visitors'] ?? 0),
            'last_click' => isset($row['last_click']) && $row['last_click'] !== null
                ? (string) $row['last_click']
                : null,
        ];
    }

    /**
     * Clicks per day for the last $days days, including days with none.
     *
     * @return array<string, int> keyed by Y-m-d
     */
    public function dailyClicks(Link $link, int $days = 14): array
    {
        $days = max(1, $days);
        $since = gmdate('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days'));

        // The two drivers spell "take the date part" differently.
        $dayExpression = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? "strftime('%Y-%m-%d', clicked_at)"
            : 'DATE(clicked_at)';

        $statement = $this->pdo->prepare(
            "SELECT {$dayExpression} AS day, COUNT(*) AS hits
               FROM clicks
              WHERE link_id = :link_id AND clicked_at >= :since
           GROUP BY day
           ORDER BY day ASC"
        );
        $statement->execute(['link_id' => $link->id, 'since' => $since]);

        $counts = [];

        foreach ($statement->fetchAll() as $row) {
            $counts[(string) $row['day']] = (int) $row['hits'];
        }

        $series = [];

        for ($offset = $days - 1; $offset >= 0; $offset--) {
            $day = gmdate('Y-m-d', strtotime("-{$offset} days"));
            $series[$day] = $counts[$day] ?? 0;
        }

        return $series;
    }

    /**
     * @return array<int, array{source: string, hits: int}>
     */
    public function topReferrers(Link $link, int $limit = 5): array
    {
        $limit = max(1, min(50, $limit));

        $statement = $this->pdo->prepare(
            "SELECT referer, COUNT(*) AS hits
               FROM clicks
              WHERE link_id = :link_id
           GROUP BY referer
           ORDER BY hits DESC
              LIMIT {$limit}"
        );
        $statement->execute(['link_id' => $link->id]);

        $referrers = [];

        foreach ($statement->fetchAll() as $row) {
            $referer = $row['referer'] ?? null;
            $host = $referer !== null && $referer !== ''
                ? (string) (parse_url((string) $referer, PHP_URL_HOST) ?: $referer)
                : 'Direct or unknown';

            $referrers[] = ['source' => $host, 'hits' => (int) $row['hits']];
        }

        return $referrers;
    }

    /** How many links this visitor created recently, for rate limiting. */
    public function countRecentByIp(string $ipHash, int $minutes = 60): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) AS hits
               FROM links
              WHERE created_ip_hash = :ip_hash AND created_at >= :since'
        );
        $statement->execute([
            'ip_hash' => $ipHash,
            'since' => gmdate('Y-m-d H:i:s', time() - ($minutes * 60)),
        ]);

        $row = $statement->fetch() ?: [];

        return (int) ($row['hits'] ?? 0);
    }

    public function total(): int
    {
        $row = $this->pdo->query('SELECT COUNT(*) AS hits FROM links')->fetch() ?: [];

        return (int) ($row['hits'] ?? 0);
    }

    private function insert(
        string $code,
        string $targetUrl,
        string $createdAt,
        ?string $expiresAt,
        ?string $ipHash,
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO links (code, target_url, created_at, expires_at, created_ip_hash, click_count)
                  VALUES (:code, :target_url, :created_at, :expires_at, :ip_hash, 0)'
        );
        $statement->execute([
            'code' => $code,
            'target_url' => $targetUrl,
            'created_at' => $createdAt,
            'expires_at' => $expiresAt,
            'ip_hash' => $ipHash,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function isUniqueViolation(PDOException $e): bool
    {
        return (string) $e->getCode() === self::UNIQUE_VIOLATION;
    }
}
