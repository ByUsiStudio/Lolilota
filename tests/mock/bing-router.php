<?php
/**
 * Bing Autosuggest 模拟服务（仅用于本地联调与自动化测试）
 *
 * 用法：
 *   php -S 127.0.0.1:8791 tests/mock/bing-router.php
 *
 * 然后让后端指向它：
 *   SEARCH_SUGGEST_URL=http://127.0.0.1:8791/osjson.aspx
 *
 * `/fail.aspx` 固定返回 500，用来验证后端的主/备接口切换；
 * 关键词 `fail` 也会返回 500，用来验证前端的跨域兜底逻辑。
 */

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (!in_array($path, ['/osjson.aspx', '/fallback.aspx', '/fail.aspx', '/'], true)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'not found';
    exit;
}

$query = trim((string) ($_GET['query'] ?? $_GET['q'] ?? ''));

if ($path === '/fail.aspx' || $query === 'fail') {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'upstream error';
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

echo json_encode([
    $query,
    $query === '' ? [] : [
        $query,
        $query . ' 是什么',
        $query . ' 教程',
        $query . ' 官网',
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
