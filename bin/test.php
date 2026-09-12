#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Run with: php bin/test.php
 *
 * No PHPUnit on purpose - the project has no dependencies, and the test
 * suite should not be the thing that introduces one.
 */

use App\CodeGenerator;
use App\Config;
use App\Database;
use App\Link;
use App\LinkRepository;
use App\Request;
use App\Router;
use App\UrlValidator;
use App\ValidationException;

require dirname(__DIR__) . '/src/bootstrap.php';

final class Tests
{
    private static int $passed = 0;

    /** @var array<int, string> */
    private static array $failures = [];

    public static function run(string $name, Closure $body): void
    {
        try {
            $body();
            self::$passed++;
            fwrite(STDOUT, "  ok  {$name}\n");
        } catch (Throwable $e) {
            self::$failures[] = $name . ' - ' . $e->getMessage();
            fwrite(STDOUT, "FAIL  {$name}\n        " . $e->getMessage() . "\n");
        }
    }

    public static function assertTrue(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    public static function assertSame(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException(sprintf(
                '%s (expected %s, got %s)',
                $message,
                var_export($expected, true),
                var_export($actual, true)
            ));
        }
    }

    public static function assertThrows(string $exception, Closure $body, string $message): void
    {
        try {
            $body();
        } catch (Throwable $e) {
            if ($e instanceof $exception) {
                return;
            }

            throw new RuntimeException($message . ' (threw ' . $e::class . ' instead)');
        }

        throw new RuntimeException($message . ' (nothing was thrown)');
    }

    public static function report(): int
    {
        $failed = count(self::$failures);

        fwrite(STDOUT, "\n" . self::$passed . " passed, {$failed} failed\n");

        return $failed === 0 ? 0 : 1;
    }
}

// ---------------------------------------------------------------- fixtures

$databaseFile = sys_get_temp_dir() . '/snip-test-' . getmypid() . '.sqlite';

foreach ([$databaseFile, $databaseFile . '-wal', $databaseFile . '-shm'] as $stale) {
    if (is_file($stale)) {
        unlink($stale);
    }
}

Config::set([
    'app_name' => 'Snip',
    'base_url' => 'https://snip.test',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $databaseFile],
    'code_length' => 7,
    'hash_key' => 'testing',
    'debug' => true,
]);

Database::swap(null);

$pdo = Database::connection();
$codes = new CodeGenerator(7);
$repository = new LinkRepository($pdo, $codes);

// ------------------------------------------------------------------ codes

Tests::run('generated codes are the configured length', function () use ($codes): void {
    Tests::assertSame(7, strlen($codes->generate()), 'wrong code length');
});

Tests::run('generated codes avoid look-alike characters', function () use ($codes): void {
    $sample = '';

    for ($i = 0; $i < 200; $i++) {
        $sample .= $codes->generate();
    }

    Tests::assertSame(0, (int) preg_match('/[0O1lI]/', $sample), 'ambiguous character in code');
});

Tests::run('aliases are validated', function () use ($codes): void {
    Tests::assertTrue($codes->isValidAlias('spring-sale'), 'hyphenated alias should pass');
    Tests::assertTrue(!$codes->isValidAlias('ab'), 'two characters is too short');
    Tests::assertTrue(!$codes->isValidAlias('has space'), 'spaces are not allowed');
    Tests::assertTrue(!$codes->isValidAlias(str_repeat('a', 33)), '33 characters is too long');
    Tests::assertTrue($codes->isReserved('API'), 'reserved words are case insensitive');
});

// ------------------------------------------------------------- validation

$validator = new UrlValidator('snip.test', false);

Tests::run('a bare domain gets https', function () use ($validator): void {
    Tests::assertSame('https://example.com/a', $validator->normalise('example.com/a'), 'scheme not added');
});

Tests::run('non-http schemes are rejected', function () use ($validator): void {
    Tests::assertThrows(ValidationException::class, fn () => $validator->normalise('javascript:alert(1)'), 'javascript: allowed');
    Tests::assertThrows(ValidationException::class, fn () => $validator->normalise('ftp://example.com'), 'ftp allowed');
});

Tests::run('empty input is rejected', function () use ($validator): void {
    Tests::assertThrows(ValidationException::class, fn () => $validator->normalise('   '), 'blank input allowed');
});

Tests::run('credentials in the host are rejected', function () use ($validator): void {
    Tests::assertThrows(
        ValidationException::class,
        fn () => $validator->normalise('https://paypal.com:x@evil.example/login'),
        'userinfo allowed'
    );
});

Tests::run('the shortener will not shorten itself', function () use ($validator): void {
    Tests::assertThrows(ValidationException::class, fn () => $validator->normalise('https://snip.test/abc'), 'self link allowed');
});

Tests::run('header injection characters are stripped', function () use ($validator): void {
    $url = $validator->normalise("https://example.com/a\r\nX-Injected:1");

    Tests::assertTrue(!str_contains($url, "\r") && !str_contains($url, "\n"), 'CR/LF survived');
});

Tests::run('private addresses are blocked when asked', function (): void {
    $strict = new UrlValidator(null, true);

    foreach (['http://127.0.0.1/x', 'http://10.1.1.1/x', 'http://192.168.0.5/x', 'http://[::1]/x'] as $url) {
        Tests::assertThrows(ValidationException::class, fn () => $strict->normalise($url), "allowed {$url}");
    }
});

// ------------------------------------------------------------- repository

Tests::run('a link round-trips through the database', function () use ($repository): void {
    $link = $repository->create('https://example.com/long/path', null, 'hash-a');

    Tests::assertSame(7, strlen($link->code), 'unexpected code length');

    $found = $repository->findByCode($link->code);

    Tests::assertTrue($found instanceof Link, 'link not found by code');
    Tests::assertSame('https://example.com/long/path', $found->targetUrl, 'wrong target stored');
});

Tests::run('unknown codes return null', function () use ($repository): void {
    Tests::assertSame(null, $repository->findByCode('nope-nope'), 'phantom link returned');
});

Tests::run('a custom alias is used as the code', function () use ($repository): void {
    $link = $repository->create('https://example.com/sale', 'spring-sale', 'hash-a');

    Tests::assertSame('spring-sale', $link->code, 'alias not used');
});

Tests::run('a taken alias is rejected', function () use ($repository): void {
    Tests::assertThrows(
        ValidationException::class,
        fn () => $repository->create('https://example.com/other', 'spring-sale', 'hash-a'),
        'duplicate alias accepted'
    );
});

Tests::run('reserved aliases are rejected', function () use ($repository): void {
    Tests::assertThrows(
        ValidationException::class,
        fn () => $repository->create('https://example.com/api', 'api', 'hash-a'),
        'reserved alias accepted'
    );
});

Tests::run('clicks are counted once each and visitors once overall', function () use ($repository): void {
    $link = $repository->create('https://example.com/counted', null, 'hash-b');

    $repository->recordClick($link, 'visitor-1', 'https://news.example/story', 'Test/1.0');
    $repository->recordClick($link, 'visitor-1', 'https://news.example/story', 'Test/1.0');
    $repository->recordClick($link, 'visitor-2', null, 'Test/1.0');

    $summary = $repository->summary($link);

    Tests::assertSame(3, $summary['total'], 'wrong click total');
    Tests::assertSame(2, $summary['unique'], 'wrong unique visitor count');

    $reloaded = $repository->findByCode($link->code);

    Tests::assertTrue($reloaded instanceof Link, 'link disappeared');
    Tests::assertSame(3, $reloaded->clickCount, 'counter column out of step with the click rows');
});

Tests::run('the daily series covers every day in the window', function () use ($repository): void {
    $link = $repository->create('https://example.com/daily', null, 'hash-b');
    $repository->recordClick($link, 'visitor-1', null, null);

    $daily = $repository->dailyClicks($link, 14);

    Tests::assertSame(14, count($daily), 'wrong number of days');
    Tests::assertSame(1, $daily[gmdate('Y-m-d')] ?? 0, "today's clicks missing");
});

Tests::run('referrers are grouped by host', function () use ($repository): void {
    $link = $repository->create('https://example.com/referrers', null, 'hash-b');

    $repository->recordClick($link, 'visitor-1', 'https://news.example/a', null);
    $repository->recordClick($link, 'visitor-2', 'https://news.example/b', null);
    $repository->recordClick($link, 'visitor-3', null, null);

    $referrers = $repository->topReferrers($link, 5);

    Tests::assertTrue($referrers !== [], 'no referrers returned');
    Tests::assertTrue(
        in_array('news.example', array_column($referrers, 'source'), true),
        'referrer host not grouped'
    );
});

Tests::run('links created by one visitor can be counted', function () use ($repository): void {
    Tests::assertTrue($repository->countRecentByIp('hash-b', 60) >= 3, 'rate limit counter too low');
    Tests::assertSame(0, $repository->countRecentByIp('hash-unused', 60), 'phantom links counted');
});

// ------------------------------------------------------------------ model

Tests::run('expiry is respected', function (): void {
    $past = new Link(1, 'aaa', 'https://example.com', '2020-01-01 00:00:00', '2020-02-01 00:00:00', 0);
    $never = new Link(2, 'bbb', 'https://example.com', '2020-01-01 00:00:00', null, 0);
    $future = new Link(3, 'ccc', 'https://example.com', '2020-01-01 00:00:00', gmdate('Y-m-d H:i:s', time() + 3600), 0);

    Tests::assertTrue($past->hasExpired(), 'past link should have expired');
    Tests::assertTrue(!$never->hasExpired(), 'link without expiry should never expire');
    Tests::assertTrue(!$future->hasExpired(), 'future link should still work');
});

// ---------------------------------------------------------------- request

Tests::run('the install directory is worked out from the request', function (): void {
    // [SCRIPT_NAME, REQUEST_URI, expected base path, expected route path]
    $cases = [
        ['/index.php', '/abc', '', '/abc'],
        ['/index.php', '/', '', '/'],
        ['/index.php', '/abc/', '', '/abc'],
        ['/index.php', '/abc+?x=1', '', '/abc+'],
        // project sitting in a sub-directory, public/ visible in the URL
        ['/snip/public/index.php', '/snip/public/abc', '/snip/public', '/abc'],
        // root .htaccess forwarding into public/, at the domain root
        ['/public/index.php', '/abc', '', '/abc'],
        // the same forward, from a sub-directory
        ['/snip/public/index.php', '/snip/abc+', '/snip', '/abc+'],
    ];

    $script = $_SERVER['SCRIPT_NAME'] ?? null;
    $uri = $_SERVER['REQUEST_URI'] ?? null;

    foreach ($cases as [$scriptName, $requestUri, $expectedBase, $expectedPath]) {
        $_SERVER['SCRIPT_NAME'] = $scriptName;
        $_SERVER['REQUEST_URI'] = $requestUri;

        Tests::assertSame($expectedBase, Request::basePath(), "base path for {$requestUri}");
        Tests::assertSame($expectedPath, Request::path(), "route path for {$requestUri}");
    }

    $_SERVER['SCRIPT_NAME'] = $script;
    $_SERVER['REQUEST_URI'] = $uri;
});

// ----------------------------------------------------------------- router

Tests::run('routes resolve in the right order', function (): void {
    $router = new Router();
    $router->get('/', fn () => 'home');
    $router->get('/health', fn () => 'health');
    $router->get('/{code}+', fn (array $p) => 'stats:' . $p['code']);
    $router->get('/{code}', fn (array $p) => 'go:' . $p['code']);
    $router->fallback(fn () => 'missing');

    Tests::assertSame('home', $router->dispatch('GET', '/'), 'home route');
    Tests::assertSame('health', $router->dispatch('GET', '/health'), 'static route lost to the catch-all');
    Tests::assertSame('stats:abc', $router->dispatch('GET', '/abc+'), 'stats route');
    Tests::assertSame('go:abc', $router->dispatch('GET', '/abc'), 'redirect route');
    Tests::assertSame('missing', $router->dispatch('GET', '/a/b/c'), 'nested path should not match a code');
    Tests::assertSame('missing', $router->dispatch('POST', '/abc'), 'method should be honoured');
});

// ---------------------------------------------------------------- cleanup

Database::swap(null);

foreach ([$databaseFile, $databaseFile . '-wal', $databaseFile . '-shm'] as $file) {
    if (is_file($file)) {
        unlink($file);
    }
}

exit(Tests::report());
