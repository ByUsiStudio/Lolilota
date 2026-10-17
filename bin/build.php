#!/usr/bin/env php
<?php
/**
 * Lolilota 样式构建（纯 PHP，不依赖 Node.js）
 *
 * 用法：
 *   php bin/build.php              编译 Less -> css/lolilota.css 与 dist/css/lolilota.css（压缩）
 *   php bin/build.php --no-minify  不压缩（便于调试）
 *   php bin/build.php --watch      监听 src/less 变化并自动重建
 *   php bin/build.php --check      只检查产物是否最新，不写文件
 *   php bin/build.php --no-manifest 跳过扩展清单的生成与校验
 *
 * 说明：更完整的打包（扩展目录、占位符替换、站点同步）由 Node 脚本 build.cjs 负责；
 *       本脚本负责「样式」与「扩展清单」这两件不需要 Node 的事。
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "本脚本只能在命令行运行\n");
    exit(1);
}

require dirname(__DIR__) . '/public/bootstrap.php';

use Lolilota\Less\Builder;
use Lolilota\Less\LessException;

$args = array_slice($argv, 1);
$minify = !in_array('--no-minify', $args, true);
$watch = in_array('--watch', $args, true);
$check = in_array('--check', $args, true);
$withManifest = !in_array('--no-manifest', $args, true);

$builder = new Builder(LOLILOTA_ROOT);

/**
 * 生成并校验扩展清单
 *
 * 这一步很关键：manifest 里的相对路径与 chrome_settings_overrides.homepage
 * 写错，浏览器会直接拒绝加载扩展（例如
 * "Invalid value for overriding homepage url"）。
 */
function buildManifest(bool $checkOnly = false): bool
{
    $script = LOLILOTA_ROOT . '/bin/manifest.php';
    if (!is_file($script)) {
        return true;
    }

    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script)
        . ($checkOnly ? ' --check' : '');

    $output = [];
    $code = 0;
    exec($command . ' 2>&1', $output, $code);

    $text = implode(PHP_EOL, $output);
    if ($code !== 0) {
        fwrite(STDERR, $text . PHP_EOL);
        return false;
    }

    fwrite(STDOUT, $text . PHP_EOL);
    return true;
}

function report(Builder $builder, bool $minify): bool
{
    try {
        $result = $builder->build($minify);
    } catch (LessException $e) {
        fwrite(STDERR, "\033[31m✗\033[0m 编译失败：" . $e->getMessage() . PHP_EOL);
        return false;
    } catch (Throwable $e) {
        fwrite(STDERR, "\033[31m✗\033[0m 意外错误：" . $e->getMessage() . PHP_EOL);
        return false;
    }

    printf(
        "  %s  %s  %.1f KB  gzip %.1f KB  规则 %d%s\n",
        "\033[32m✓\033[0m",
        Builder::OUTPUT_FILE,
        $result['bytes'] / 1024,
        $result['gzip'] / 1024,
        $result['rules'],
        $minify ? '' : '  [未压缩]'
    );

    foreach (array_slice($result['files'], 1) as $file) {
        printf("     %s\n", $file);
    }

    foreach ($result['errors'] as $error) {
        fwrite(STDERR, "  \033[31m!\033[0m " . $error . PHP_EOL);
    }

    return $result['errors'] === [];
}

if ($check) {
    $stale = $builder->isStale();
    printf(
        "%s 产物%s\n",
        $stale ? "\033[33m•\033[0m" : "\033[32m✓\033[0m",
        $stale ? '需要重新编译（源文件有改动）' : '已是最新'
    );

    $manifestOk = $withManifest ? buildManifest(true) : true;
    exit(($stale || !$manifestOk) ? 1 : 0);
}

echo PHP_EOL . "Lolilota 构建（PHP，无需 Node.js）" . PHP_EOL . PHP_EOL;

if (!report($builder, $minify)) {
    exit(1);
}

if ($withManifest) {
    echo PHP_EOL . "  扩展清单" . PHP_EOL;
    if (!buildManifest()) {
        fwrite(STDERR, PHP_EOL . "  扩展清单生成失败，请检查 manifest.json" . PHP_EOL);
        exit(1);
    }
}

echo PHP_EOL . "  样式产物：" . Builder::OUTPUT_FILE . " 与 " . Builder::DIST_FILE . PHP_EOL . PHP_EOL;

if (!$watch) {
    exit(0);
}

echo "  监听 src/less 变化，Ctrl+C 结束…" . PHP_EOL . PHP_EOL;

$lastHash = $builder->sourceHash();

while (true) {
    usleep(700_000);

    $hash = $builder->sourceHash();
    if ($hash === $lastHash) {
        continue;
    }

    $lastHash = $hash;
    echo "  [" . date('H:i:s') . "] 检测到改动，重新编译…" . PHP_EOL;
    report($builder, $minify);
}
