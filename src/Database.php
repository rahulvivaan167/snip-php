<?php

declare(strict_types=1);

namespace App;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Lazily builds a single PDO connection and makes sure the schema exists.
 * Supports SQLite (default, zero setup) and MySQL.
 */
final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $driver = Config::string('db.driver', 'sqlite');

        self::$connection = match ($driver) {
            'sqlite' => self::sqlite(),
            'mysql' => self::mysql(),
            default => throw new RuntimeException("Unsupported database driver: {$driver}"),
        };

        return self::$connection;
    }

    public static function driver(): string
    {
        return (string) self::connection()->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /** Test helper: swap in a connection built elsewhere. */
    public static function swap(?PDO $pdo): void
    {
        self::$connection = $pdo;
    }

    private static function sqlite(): PDO
    {
        $path = Config::string('db.sqlite_path');

        if ($path === '') {
            throw new RuntimeException('db.sqlite_path is not configured.');
        }

        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException("Cannot create database directory: {$directory}");
        }

        // A zero-byte file is what you get from `touch`, so migrate that too.
        $fresh = !is_file($path) || filesize($path) === 0;

        $pdo = new PDO('sqlite:' . $path, null, null, self::options(false));
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');

        if ($fresh) {
            self::migrate($pdo, 'sqlite');
        }

        return $pdo;
    }

    private static function mysql(): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            Config::string('db.host', '127.0.0.1'),
            Config::int('db.port', 3306),
            Config::string('db.database'),
            Config::string('db.charset', 'utf8mb4')
        );

        try {
            return new PDO(
                $dsn,
                Config::string('db.username'),
                Config::string('db.password'),
                self::options(true)
            );
        } catch (PDOException $e) {
            throw new RuntimeException('Database connection failed. Check your config.php.', 0, $e);
        }
    }

    /** Runs the bundled schema file. Used on first boot for SQLite. */
    public static function migrate(PDO $pdo, string $driver): void
    {
        $file = dirname(__DIR__) . "/database/schema.{$driver}.sql";

        if (!is_file($file)) {
            throw new RuntimeException("Schema file not found: {$file}");
        }

        $sql = (string) file_get_contents($file);

        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            $pdo->exec($statement);
        }
    }

    /**
     * SQLite rejects some attributes that MySQL needs, so they are opt-in.
     *
     * @return array<int, mixed>
     */
    private static function options(bool $mysql): array
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];

        if ($mysql) {
            $options[PDO::ATTR_EMULATE_PREPARES] = false;
            $options[PDO::ATTR_STRINGIFY_FETCHES] = false;
        }

        return $options;
    }
}
