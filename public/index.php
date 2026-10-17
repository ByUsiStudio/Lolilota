<?php
/**
 * Lolilota · 前端页面入口
 *
 * 同源部署时，PHP 会把后端地址与账号配置注入页面，
 * 前端 config.js 优先读取 window.__LOLILOTA__。
 *
 * 页面模板来自构建产物 public/newtab.html（由 npm run build 生成），
 * 这里只做变量替换，模板本身仍然可以当静态页 / 扩展页面使用。
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Lolilota\Core\Config;
use Lolilota\Support\Json;

$template = __DIR__ . '/newtab.html';

if (!is_file($template)) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><title>尚未构建</title></head><body>'
        . '<h1>前端资源还没有构建</h1>'
        . '<p>请在项目根目录执行：<code>npm install &amp;&amp; npm run build</code></p>'
        . '</body></html>';
    exit;
}

$html = (string) file_get_contents($template);

// ---- 注入运行时配置 ----------------------------------------------------
$boot = [
    'apiBase'   => '',                       // 空字符串表示同源
    'mode'      => 'site',
    'providers' => array_values(array_filter(array_map(
        'trim',
        (array) Config::get('auth.providers', ['cdifit'])
    ))),
    'siteName'  => 'Cdifit',
    'appName'   => (string) Config::get('app.name', 'Lolilota'),
    'version'   => (string) Config::get('app.version', '1.1.0'),
    'debug'     => (bool) Config::get('app.debug', false),
];

$bootJson = json_encode(
    $boot,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);

$bootScript = '<script>window.__LOLILOTA__ = ' . ($bootJson ?: '{}') . ';</script>';

if (str_contains($html, '__BOOT_SCRIPT__')) {
    $html = str_replace('__BOOT_SCRIPT__', $bootScript, $html);
} else {
    // 已经构建过的页面：插到第一个 <script 之前
    $html = preg_replace('#(<script\b)#i', $bootScript . "\n    $1", $html, 1) ?? $html;
}

// ---- 安全响应头 --------------------------------------------------------
if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: SAMEORIGIN');
    // 注意：页面只在扩展 / 同源站点使用；如需嵌入第三方站点请自行放宽
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https:; "
        . "style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; "
        . "connect-src 'self' https://api.bing.com https://cn.bing.com; frame-ancestors 'self'");
}

echo $html;
