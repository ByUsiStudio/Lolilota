<?php

declare(strict_types=1);

namespace Lolilota\Core;

use Lolilota\Support\Json;

/**
 * 极简路由
 *
 * 支持：
 *   - 静态路径：/api/v1/session
 *   - 参数路径：/api/v1/history/{id}
 *   - 方法区分：GET / POST / DELETE ...
 */
final class Router
{
    /** @var list<array{method:string, pattern:string, handler:callable, regex:string}> */
    private array $routes = [];

    private string $prefix = '';

    public function setPrefix(string $prefix): void
    {
        $this->prefix = '/' . trim($prefix, '/');
    }

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function put(string $pattern, callable $handler): void
    {
        $this->add('PUT', $pattern, $handler);
    }

    public function delete(string $pattern, callable $handler): void
    {
        $this->add('DELETE', $pattern, $handler);
    }

    public function options(string $pattern, callable $handler): void
    {
        $this->add('OPTIONS', $pattern, $handler);
    }

    private function add(string $method, string $pattern, callable $handler): void
    {
        $full = $this->prefix . '/' . trim($pattern, '/');
        $full = '/' . trim($full, '/');
        if ($full === '/') {
            $full = '/';
        }

        // {id} -> 命名捕获
        $regex = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#',
            static fn (array $m) => '(?P<' . $m[1] . '>[^/]+)',
            $full
        );

        $this->routes[] = [
            'method'  => strtoupper($method),
            'pattern' => $full,
            'handler' => $handler,
            'regex'   => '#^' . $regex . '$#',
        ];
    }

    /**
     * 匹配并执行
     *
     * @param array<string, mixed> $context 传给处理器的依赖
     */
    public function dispatch(string $method, string $path, array $context = []): void
    {
        $method = strtoupper($method);
        $path = '/' . trim($path, '/');
        $allowed = [];

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path, $matches) !== 1) {
                continue;
            }

            if ($route['method'] !== $method) {
                $allowed[] = $route['method'];
                continue;
            }

            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = urldecode($value);
                }
            }

            ($route['handler'])($params, $context);
            return;
        }

        if ($allowed !== []) {
            header('Allow: ' . implode(', ', array_unique($allowed)));
            Json::error('method_not_allowed', '不支持的请求方法：' . $method, 405);
        }

        Json::error('not_found', '接口不存在：' . $path, 404);
    }

    /** @return list<string> */
    public function paths(): array
    {
        return array_map(
            static fn (array $r) => $r['method'] . ' ' . $r['pattern'],
            $this->routes
        );
    }
}
