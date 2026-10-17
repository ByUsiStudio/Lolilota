<?php
/**
 * Lolilota · 入口路由
 *
 * 两个用途：
 *   1. PHP 内置服务器：php -S 127.0.0.1:8787 -t public public/router.php
 *      已存在的静态文件直接返回，其余交给 index.php
 *   2. Nginx / Apache 的 try_files / rewrite 目标（可选）
 *
 * 注意：本文件在 SAPI 为 cli-server 时返回 false 让内置服务器自己发静态文件，
 * 这样可以获得正确的 Content-Type 与 Range 支持。
 *
 * 本文件属于后端源码，由构建脚本的同步逻辑显式保护，不会被清理掉。
 */

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__ . str_replace('/', DIRECTORY_SEPARATOR, $path);

// ---- 内置服务器：静态文件交给它自己处理 --------------------------------
if (PHP_SAPI === 'cli-server') {
    // 扩展用的页面模板含有构建占位符，运行时配置由 index.php 注入；
    // 直接访问静态模板会缺少 __LOLILOTA__，因此统一跳到站点入口。
    if ($path === '/newtab.html') {
        header('Location: /', true, 302);
        return true;
    }

    if ($path !== '/' && is_file($file)) {
        // 禁止把配置、日志、数据文件当静态资源下载（运行时数据本就在 web 根之外）
        $blocked = ['/config/', '/var/', '/app/'];
        foreach ($blocked as $needle) {
            if (str_starts_with($path, $needle)) {
                http_response_code(403);
                header('Content-Type: text/plain; charset=utf-8');
                echo 'Forbidden';
                return true;
            }
        }
        return false;
    }
}

// ---- /api 走接口入口 ---------------------------------------------------
if (str_starts_with($path, '/api')) {
    require __DIR__ . '/api.php';
    return true;
}

// ---- 其余走页面入口 ----------------------------------------------------
require __DIR__ . '/index.php';
return true;
