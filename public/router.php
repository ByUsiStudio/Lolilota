<?php
/**
 * Lolilota · 入口路由
 *
 * 用途：
 *   1. PHP 内置服务器：php -S 127.0.0.1:8787 -t public public/router.php
 *   2. Nginx / Apache 的 try_files / rewrite 目标（可选）
 *
 * 开发模式（环境变量 LOLILOTA_DEV=1）下，对 css/lolilota.css 的请求会
 * 触发「按需编译」：源文件有改动就用 PHP 编译器重新编译并返回，
 * 因此没有 Node.js 也能改样式、刷新即见。
 *
 * 本文件属于后端源码，构建脚本的同步与清理都会跳过 .php 文件。
 */

declare(strict_types=1);

/**
 * 开发模式：实时提供编译后的样式
 *
 * 判断依据是「源文件指纹」而不是文件时间：改完、还原、切分支都能正确识别。
 * 编译结果只写 var/cache，不覆盖 css/ 与 dist/css/ 里的发布产物。
 */
function lolilota_dev_style(string $root): bool
{
    if (!is_file($root . '/src/less/style.less')) {
        return false;
    }

    require_once $root . '/public/bootstrap.php';

    try {
        $builder = new Lolilota\Less\Builder($root);

        if ($builder->devCacheStale()) {
            $css = $builder->devCss();
        } else {
            $css = $builder->devCachedCss();

            // 缓存缺失时回落到已发布的产物
            if ($css === null) {
                $published = $root . '/css/lolilota.css';
                $css = is_file($published) ? (string) @file_get_contents($published) : '';
            }
        }
    } catch (Throwable $e) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo '/* Less 编译失败：' . $e->getMessage() . ' */';
        return true;
    }

    if ($css === '') {
        return false;
    }

    header('Content-Type: text/css; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    header('X-Lolilota-Style: php-dev-compiled');
    echo $css;

    return true;
}

$root = dirname(__DIR__);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__ . str_replace('/', DIRECTORY_SEPARATOR, $path);

$isDev = getenv('LOLILOTA_DEV') === '1' || getenv('LOLILOTA_DEV') === 'true';

// ---- 开发模式：样式实时编译 -------------------------------------------
if ($isDev && ($path === '/css/lolilota.css' || $path === '/css/style.css')) {
    if (lolilota_dev_style($root)) {
        return true;
    }
}

// ---- 内置服务器：静态文件交给它自己处理 --------------------------------
if (PHP_SAPI === 'cli-server') {
    // 扩展用的页面模板含有构建占位符，运行时配置由 index.php 注入；
    // 直接访问静态模板会缺少 __LOLILOTA__，因此统一跳到站点入口。
    if ($path === '/newtab.html') {
        header('Location: /', true, 302);
        return true;
    }

    if ($path !== '/' && is_file($file)) {
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
