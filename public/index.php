<?php

declare(strict_types=1);

use App\CodeGenerator;
use App\Config;
use App\Csrf;
use App\Database;
use App\Http;
use App\Link;
use App\LinkRepository;
use App\Request;
use App\Router;
use App\UrlValidator;
use App\ValidationException;
use App\View;

require dirname(__DIR__) . '/src/bootstrap.php';

// Registered before anything else so a failure while connecting to the
// database is handled the same way as one inside a route.
set_exception_handler(static function (Throwable $e): void {
    error_log('[snip] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

    if (Config::bool('debug')) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo $e::class . ': ' . $e->getMessage() . "\n\n" . $e->getTraceAsString();

        return;
    }

    if (Request::wantsJson()) {
        Http::json(['error' => 'Something went wrong.'], 500);

        return;
    }

    View::render('error', [
        'title' => 'Something went wrong',
        'appName' => Config::string('app_name', 'Snip'),
        'baseUrl' => Request::baseUrl(),
    ], 500);
});

$baseUrl = Request::baseUrl();
$appName = Config::string('app_name', 'Snip');

$repository = new LinkRepository(
    Database::connection(),
    new CodeGenerator(Config::int('code_length', 7))
);

$validator = new UrlValidator(
    parse_url($baseUrl, PHP_URL_HOST) ?: null,
    Config::bool('block_private_hosts', true)
);

/** Pull the one-shot message left behind by the previous request. */
$takeFlash = static function (): array {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $flash = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);

    return is_array($flash) ? $flash : [];
};

$setFlash = static function (array $flash): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $_SESSION['flash'] = $flash;
};

/** Turns the "keep for" dropdown into a timestamp, or null for forever. */
$expiryFromDays = static function (?string $input): ?string {
    $days = max(0, (int) ($input ?? 0));

    return $days > 0 ? gmdate('Y-m-d H:i:s', time() + ($days * 86400)) : null;
};

$guardRateLimit = static function (string $ipHash) use ($repository): void {
    $limit = Config::int('rate_limit_per_hour', 30);

    if ($limit > 0 && $repository->countRecentByIp($ipHash) >= $limit) {
        throw new ValidationException("You have created {$limit} links in the last hour. Try again later.");
    }
};

$router = new Router();

$router->get('/', static function () use ($takeFlash, $appName, $baseUrl): void {
    $flash = $takeFlash();

    View::render('home', [
        'title' => $appName,
        'appName' => $appName,
        'baseUrl' => $baseUrl,
        'csrf' => Csrf::token(),
        'result' => $flash['result'] ?? null,
        'error' => $flash['error'] ?? null,
        'submitted' => $flash['submitted'] ?? '',
    ]);
});

$router->post('/shorten', static function () use (
    $repository,
    $validator,
    $setFlash,
    $expiryFromDays,
    $guardRateLimit,
    $baseUrl
): void {
    $submitted = Request::post('url') ?? '';

    if (!Csrf::check(Request::post('csrf'))) {
        $setFlash(['error' => 'Your session expired. Please try again.', 'submitted' => $submitted]);
        Http::redirect($baseUrl . '/', 303);

        return;
    }

    try {
        $ipHash = Request::ipHash();
        $guardRateLimit($ipHash);

        $link = $repository->create(
            $validator->normalise($submitted),
            trim(Request::post('alias') ?? '') ?: null,
            $ipHash,
            $expiryFromDays(Request::post('expires_in_days'))
        );

        $setFlash([
            'result' => [
                'code' => $link->code,
                'short_url' => $baseUrl . '/' . $link->code,
                'stats_url' => $baseUrl . '/' . $link->code . '+',
                'target_url' => $link->targetUrl,
                'expires_at' => $link->expiresAt,
            ],
        ]);
    } catch (ValidationException $e) {
        $setFlash(['error' => $e->getMessage(), 'submitted' => $submitted]);
    }

    Http::redirect($baseUrl . '/', 303);
});

$router->get('/health', static function () use ($repository): void {
    Http::json(['status' => 'ok', 'links' => $repository->total()]);
});

// Stats live at the short URL with a "+" appended, the same convention
// bit.ly uses, so anyone with the link can inspect it before clicking.
$router->get('/{code}+', static function (array $parameters) use ($repository, $appName, $baseUrl): void {
    $link = $repository->findByCode($parameters['code']);

    if (!$link instanceof Link) {
        View::render('not-found', ['title' => 'Link not found', 'appName' => $appName, 'baseUrl' => $baseUrl], 404);

        return;
    }

    View::render('stats', [
        'title' => $appName . '/' . $link->code,
        'appName' => $appName,
        'baseUrl' => $baseUrl,
        'link' => $link,
        'shortUrl' => $baseUrl . '/' . $link->code,
        'summary' => $repository->summary($link),
        'daily' => $repository->dailyClicks($link, 14),
        'referrers' => $repository->topReferrers($link, 5),
    ]);
});

$router->get('/{code}', static function (array $parameters) use ($repository, $appName, $baseUrl): void {
    $link = $repository->findByCode($parameters['code']);

    if (!$link instanceof Link) {
        View::render('not-found', ['title' => 'Link not found', 'appName' => $appName, 'baseUrl' => $baseUrl], 404);

        return;
    }

    if ($link->hasExpired()) {
        View::render('expired', [
            'title' => 'Link expired',
            'appName' => $appName,
            'baseUrl' => $baseUrl,
            'link' => $link,
        ], 410);

        return;
    }

    $repository->recordClick(
        $link,
        Request::ipHash(),
        Request::header('Referer'),
        Request::header('User-Agent')
    );

    Http::redirect($link->targetUrl);
});

$router->post('/api/links', static function () use (
    $repository,
    $validator,
    $expiryFromDays,
    $guardRateLimit,
    $baseUrl
): void {
    $token = Config::string('api_token');

    if ($token !== '') {
        $provided = Request::header('Authorization') ?? '';
        $provided = str_starts_with($provided, 'Bearer ') ? substr($provided, 7) : '';

        if (!hash_equals($token, $provided)) {
            Http::json(['error' => 'Unauthorised'], 401);

            return;
        }
    }

    $body = Request::jsonBody();
    $url = isset($body['url']) && is_string($body['url']) ? $body['url'] : (Request::post('url') ?? '');
    $alias = isset($body['alias']) && is_string($body['alias']) ? $body['alias'] : null;
    $days = isset($body['expires_in_days']) && is_scalar($body['expires_in_days'])
        ? (string) $body['expires_in_days']
        : null;

    try {
        $ipHash = Request::ipHash();
        $guardRateLimit($ipHash);

        $link = $repository->create($validator->normalise($url), $alias, $ipHash, $expiryFromDays($days));
    } catch (ValidationException $e) {
        Http::json(['error' => $e->getMessage()], 422);

        return;
    }

    Http::json([
        'code' => $link->code,
        'short_url' => $baseUrl . '/' . $link->code,
        'stats_url' => $baseUrl . '/' . $link->code . '+',
        'target_url' => $link->targetUrl,
        'expires_at' => $link->expiresAt,
    ], 201);
});

$router->get('/api/links/{code}', static function (array $parameters) use ($repository): void {
    $link = $repository->findByCode($parameters['code']);

    if (!$link instanceof Link) {
        Http::json(['error' => 'Not found'], 404);

        return;
    }

    Http::json([
        'code' => $link->code,
        'target_url' => $link->targetUrl,
        'created_at' => $link->createdAt,
        'expires_at' => $link->expiresAt,
        'clicks' => $repository->summary($link),
    ]);
});

$router->fallback(static function () use ($appName, $baseUrl): void {
    if (Request::wantsJson()) {
        Http::json(['error' => 'Not found'], 404);

        return;
    }

    View::render('not-found', ['title' => 'Not found', 'appName' => $appName, 'baseUrl' => $baseUrl], 404);
});

$router->dispatch(Request::method(), Request::path());
