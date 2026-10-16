<?php
/**
 * Lolilota · 后端引导文件
 *
 * 所有入口（public/index.php、public/api.php、public/router.php）都从这里开始。
 * 职责：目录常量、类自动加载、配置、错误处理开关。
 */

declare(strict_types=1);

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('Lolilota 后端需要 PHP 8.1 或更高版本，当前版本：' . PHP_VERSION);
}

// ---- 目录常量 ----------------------------------------------------------
define('LOLILOTA_ROOT', dirname(__DIR__));
define('LOLILOTA_APP', LOLILOTA_ROOT . '/app');
define('LOLILOTA_CONFIG_DIR', LOLILOTA_ROOT . '/config');
define('LOLILOTA_PUBLIC', __DIR__);
define('LOLILOTA_START', microtime(true));

// 运行时数据目录：会话、账号、历史。放在 public 之外，避免被直接下载。
define('LOLILOTA_VAR', getenv('LOLILOTA_VAR') ?: (LOLILOTA_ROOT . '/var'));

// ---- 类自动加载 --------------------------------------------------------
// 约定：app/Core/JsonStore.php -> Lolilota\Core\JsonStore
spl_autoload_register(static function (string $class): void {
    $prefix = 'Lolilota\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = LOLILOTA_APP . '/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($path)) {
        require_once $path;
    }
});

// ---- 加载配置 ----------------------------------------------------------
require_once LOLILOTA_APP . '/Support/helpers.php';

$config = require LOLILOTA_CONFIG_DIR . '/config.php';
Lolilota\Core\Config::load($config);

// ---- 错误处理 ----------------------------------------------------------
// 生产环境只记日志，绝不把堆栈输出到响应体（JSON 接口会因此变成非法 JSON）
$debug = (bool) Lolilota\Core\Config::get('debug', false);

error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');

$logFile = Lolilota\Core\Config::get('log.file');
if ($logFile) {
    $dir = dirname($logFile);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    ini_set('error_log', $logFile);
}

// ---- 会话 --------------------------------------------------------------
// 生产建议开启 secure；本地 http 调试会自动降级
$cookieSecure = \Lolilota\Support\Http::isSecure() ? '1' : '0';
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => (bool) $cookieSecure,
    'httponly' => true,
    'samesite' => 'Lax',
]);

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    session_name(Lolilota\Core\Config::get('session.name', 'lolilota_sid'));
    @session_start();
}

// ---- 时区 --------------------------------------------------------------
date_default_timezone_set(Lolilota\Core\Config::get('app.timezone', 'Asia/Shanghai'));
