<?php
declare(strict_types=1);

namespace App;

final class Router
{
    /** @var array<int, array{method:string, pattern:string, handler:array|callable, middleware:array}> */
    private array $routes = [];
    private array $groupMiddleware = [];

    public function get(string $path, array|callable $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    public function post(string $path, array|callable $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    public function put(string $path, array|callable $handler, array $middleware = []): void
    {
        $this->add('PUT', $path, $handler, $middleware);
    }

    public function delete(string $path, array|callable $handler, array $middleware = []): void
    {
        $this->add('DELETE', $path, $handler, $middleware);
    }

    public function options(string $path, array|callable $handler, array $middleware = []): void
    {
        $this->add('OPTIONS', $path, $handler, $middleware);
    }

    public function group(array $middleware, callable $define): void
    {
        $prev = $this->groupMiddleware;
        $this->groupMiddleware = [...$prev, ...$middleware];
        $define($this);
        $this->groupMiddleware = $prev;
    }

    private function add(string $method, string $path, array|callable $handler, array $middleware): void
    {
        $pattern = preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', rtrim($path, '/') ?: '/');
        $this->routes[] = [
            'method' => $method,
            'pattern' => '#^' . $pattern . '$#',
            'handler' => $handler,
            'middleware' => [...$this->groupMiddleware, ...$middleware],
        ];
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = (string) parse_url($uri, PHP_URL_PATH);
        $base = base_path();
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }
        $path = '/' . trim(rawurldecode($path), '/');

        $allowed = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['pattern'], $path, $m)) {
                continue;
            }
            if ($route['method'] !== $method) {
                $allowed = true;
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            foreach ($route['middleware'] as $mw) {
                Middleware::run($mw);
            }
            // Token-authenticated routes (APIs, webhooks) opt out with the 'no_csrf' middleware.
            if ($method !== 'GET' && !in_array('no_csrf', $route['middleware'], true)) {
                verify_csrf();
            }
            $handler = $route['handler'];
            if (is_array($handler)) {
                [$class, $action] = $handler;
                (new $class())->$action(...$params);
            } else {
                $handler(...$params);
            }
            return;
        }
        abort($allowed ? 405 : 404, $allowed ? 'Method not allowed.' : '');
    }
}
