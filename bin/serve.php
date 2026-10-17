<?php
/**
 * Lolilota · 启动脚本（PHP 内置服务器）
 *
 * 这个文件既是 PHP 应用入口，也可以直接当「启动脚本」用：
 *
 *   php bin/serve.php                     默认 http://127.0.0.1:8787
 *   php bin/serve.php 0.0.0.0 8080        指定地址与端口
 *   php bin/serve.php --dev               开发模式（样式按需实时编译）
 *
 * 它不依赖 Node.js：只用 PHP 自带的 cli-server 起服务。
 * Windows 上用 bin\serve.ps1 / serve.cmd，类 Unix 上用 bin/serve.sh。
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// 不在 public/ 里，避免被当成静态脚本访问；这里只做引导
$root = dirname(__DIR__);

// ---- 参数解析 ----------------------------------------------------------
$host = '0.0.0.0';
$port = 8787;
$dev = false;
$positional = [];

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dev' || $arg === '-d') {
        $dev = true;
        continue;
    }
    if ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, <<<TXT

Lolilota 启动脚本

  php bin/serve.php [地址] [端口] [--dev]

  地址    监听地址，默认 127.0.0.1
  端口    监听端口，默认 8787
  --dev   开发模式：样式按需实时编译（改 .less 刷新页面即可）

TXT);
        exit(0);
    }
    $positional[] = $arg;
}

if (isset($positional[0])) {
    $host = $positional[0];
}
if (isset($positional[1]) && ctype_digit($positional[1])) {
    $port = (int) $positional[1];
}

// ---- 构建检查 ----------------------------------------------------------
$entry = $root . '/src/less/style.less';
$output = $root . '/css/lolilota.css';

if (!is_dir($root . '/public')) {
    fwrite(STDERR, "找不到 public/ 目录，请在项目根目录运行本脚本\n");
    exit(1);
}

if (!is_file($output) && is_file($entry)) {
    fwrite(STDOUT, "首次运行：样式还没编译，正在用 PHP 编译 Less…\n");
    require $root . '/public/bootstrap.php';
    try {
        $result = (new Lolilota\Less\Builder($root))->build(true);
        fwrite(STDOUT, sprintf("  已生成 css/lolilota.css（%.1f KB）\n", $result['bytes'] / 1024));
    } catch (Throwable $e) {
        fwrite(STDERR, "  编译失败：" . $e->getMessage() . "\n");
        fwrite(STDERR, "  可以稍后手动执行：php bin/build.php\n");
    }
}

// ---- 启动 --------------------------------------------------------------
$documentRoot = $root . '/public';
$router = $documentRoot . '/router.php';
$url = sprintf('http://%s:%d', $host === '0.0.0.0' ? '127.0.0.1' : $host, $port);

putenv('LOLILOTA_DEV=' . ($dev ? '1' : '0'));

$php = PHP_BINARY;

fwrite(STDOUT, <<<TXT

  Lolilota 已启动
  ------------------------------------------------------------
  访问地址   {$url}
  站点根目录 {$documentRoot}
  运行模式   {$dev}

  TXT);
fwrite(STDOUT, $dev
    ? "样式：开发模式，修改 src/less/*.less 后刷新页面即可生效\n"
    : "样式：使用编译产物 css/lolilota.css（改样式后执行 php bin/build.php）\n");

fwrite(STDOUT, <<<TXT

  扩展形态：浏览器扩展加载仓库根目录（读取 dist/）
  停止服务：Ctrl+C

TXT);

$command = sprintf(
    '%s -d variables_order=EGPCS -S %s:%d -t %s %s',
    escapeshellarg($php),
    $host,
    $port,
    escapeshellarg($documentRoot),
    escapeshellarg($router)
);

// 用 passthru 保持输出直通，Ctrl+C 能同时结束子进程
passthru($command, $exitCode);

exit((int) $exitCode);
