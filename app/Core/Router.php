<?php
declare(strict_types=1);

namespace App\Core;

final class Router
{
    private array $routes = [];

    public function get(string $path, callable|array $handler, array $middleware = []): void { $this->add('GET', $path, $handler, $middleware); }
    public function post(string $path, callable|array $handler, array $middleware = []): void { $this->add('POST', $path, $handler, $middleware); }

    private function add(string $method, string $path, callable|array $handler, array $middleware): void
    {
        $variables = [];
        $regex = preg_replace_callback('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', function ($m) use (&$variables) {
            $variables[] = $m[1];
            return '([0-9]+)';
        }, $path);
        $this->routes[] = compact('method', 'path', 'handler', 'middleware', 'regex', 'variables');
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = rawurldecode(parse_url($uri, PHP_URL_PATH) ?: '/');
        $path = $path !== '/' ? rtrim($path, '/') : '/';
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method || !preg_match('#^' . $route['regex'] . '$#', $path, $matches)) continue;
            array_shift($matches);
            foreach ($route['middleware'] as $middleware) {
                if (!$middleware()) return;
            }
            $handler = $route['handler'];
            if (is_array($handler)) {
                [$class, $action] = $handler;
                (new $class())->{$action}(...$matches);
            } else {
                $handler(...$matches);
            }
            return;
        }
        http_response_code(404);
        View::render('errors/404', ['title' => '页面不存在']);
    }
}

