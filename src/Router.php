<?php

declare(strict_types=1);

namespace App;

use Closure;

/**
 * About as small as a router gets: a list of compiled patterns per method.
 *
 * Patterns use {name} placeholders, e.g. "/{code}" or "/{code}+", and the
 * matched values are handed to the handler as an associative array.
 */
final class Router
{
    /** @var array<int, array{method: string, regex: string, handler: Closure}> */
    private array $routes = [];

    private ?Closure $fallback = null;

    public function get(string $pattern, Closure $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, Closure $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function fallback(Closure $handler): void
    {
        $this->fallback = $handler;
    }

    public function add(string $method, string $pattern, Closure $handler): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'regex' => self::compile($pattern),
            'handler' => $handler,
        ];
    }

    public function dispatch(string $method, string $path): mixed
    {
        $method = strtoupper($method);

        // HEAD is handled as GET; PHP drops the body for us.
        $lookup = $method === 'HEAD' ? 'GET' : $method;

        foreach ($this->routes as $route) {
            if ($route['method'] !== $lookup) {
                continue;
            }

            if (preg_match($route['regex'], $path, $matches) !== 1) {
                continue;
            }

            $parameters = [];

            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $parameters[$key] = $value;
                }
            }

            return ($route['handler'])($parameters);
        }

        if ($this->fallback instanceof Closure) {
            return ($this->fallback)([]);
        }

        return null;
    }

    private static function compile(string $pattern): string
    {
        $segments = preg_split(
            '/(\{[a-zA-Z_][a-zA-Z0-9_]*\})/',
            $pattern,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
        ) ?: [];

        $regex = '';

        foreach ($segments as $segment) {
            if (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*)\}$/', $segment, $matches) === 1) {
                $regex .= '(?P<' . $matches[1] . '>[A-Za-z0-9_-]{1,64})';

                continue;
            }

            $regex .= preg_quote($segment, '#');
        }

        return '#^' . $regex . '$#';
    }
}
