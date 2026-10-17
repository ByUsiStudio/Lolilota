#!/usr/bin/env php
<?php
/**
 * Lolilota 扩展清单生成与校验（纯 PHP，不依赖 Node.js）
 *
 * 作用：
 *   把仓库根目录的 manifest.json（以仓库根为基准）转换成
 *   dist/manifest.json（以 dist/ 为基准，自包含），并校验：
 *     - chrome_settings_overrides.homepage 必须是绝对 URL（Chrome 硬性要求）
 *     - chrome_url_overrides.newtab 指向的文件必须存在
 *     - icons 指向的每个文件必须存在
 *     - manifest_version 必须是 3
 *
 * 用法：
 *   php bin/manifest.php            生成并校验 dist/manifest.json
 *   php bin/manifest.php --check    只校验，不写文件
 *
 * 为什么需要这一步：浏览器扩展加载的是 dist/ 目录，
 * manifest 里的路径必须相对 dist/ 才成立。
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "本脚本只能在命令行运行\n");
    exit(1);
}

require dirname(__DIR__) . '/public/bootstrap.php';

$args = array_slice($argv, 1);
$checkOnly = in_array('--check', $args, true);

$source = LOLILOTA_ROOT . '/manifest.json';
$target = LOLILOTA_ROOT . '/dist/manifest.json';
$distDir = LOLILOTA_ROOT . '/dist';

$errors = [];
$notes = [];

// ---------------------------------------------------------------------------
// 读取
// ---------------------------------------------------------------------------
if (!is_file($source)) {
    fwrite(STDERR, "找不到 $source\n");
    exit(1);
}

$raw = (string) file_get_contents($source);
$manifest = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw, true);

if (!is_array($manifest)) {
    fwrite(STDERR, "manifest.json 不是合法 JSON：" . json_last_error_msg() . "\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// 路径改写：仓库根基准 -> dist 基准
// ---------------------------------------------------------------------------
/**
 * 把以仓库根为基准的相对路径改成以 dist/ 为基准。
 * 已经是绝对 URL、data: URL、以 / 开头的路径都原样保留。
 */
$toDistRelative = static function (string $path): string {
    if ($path === '') {
        return $path;
    }

    // 绝对 URL / data URL / 扩展内绝对路径
    if (preg_match('#^[a-zA-Z][\w+.-]*:#', $path) === 1 || str_starts_with($path, '//')) {
        return $path;
    }

    if (str_starts_with($path, '/')) {
        return $path;
    }

    // 去掉 dist/ 前缀（源指向 dist 内），其余路径补上 dist/
    if (str_starts_with($path, 'dist/')) {
        return substr($path, strlen('dist/'));
    }

    return 'dist/' . $path;
};

if (isset($manifest['icons']) && is_array($manifest['icons'])) {
    foreach ($manifest['icons'] as $size => $path) {
        if (is_string($path)) {
            $manifest['icons'][$size] = $toDistRelative($path);
        }
    }
}

foreach (['chrome_url_overrides', 'chrome_settings_overrides'] as $section) {
    if (!isset($manifest[$section]) || !is_array($manifest[$section])) {
        continue;
    }

    foreach ($manifest[$section] as $key => $path) {
        if (!is_string($path)) {
            continue;
        }

        /*
         * homepage 必须是绝对 URL（Chrome 校验很严，相对路径会直接拒绝加载），
         * 而它只对 Firefox 有意义；源 manifest 里如果写了相对路径，
         * 这里直接丢掉，避免把无效字段带进扩展。
         */
        if ($section === 'chrome_settings_overrides' && $key === 'homepage') {
            if (preg_match('#^https?://#i', $path) !== 1) {
                $notes[] = 'chrome_settings_overrides.homepage 不是绝对 URL，已从 dist/manifest.json 中移除'
                    . '（新标签页接管由 chrome_url_overrides.newtab 完成）';
                continue;
            }
            continue;
        }

        $manifest[$section][$key] = $toDistRelative($path);
    }

    // 清理空对象，避免 manifest 里出现空壳字段
    if ($manifest[$section] === []) {
        unset($manifest[$section]);
    }
}

// 其它可能出现的路径字段
foreach (['action', 'options_ui', 'background', 'devtools_page'] as $section) {
    if (!isset($manifest[$section]) || !is_array($manifest[$section])) {
        continue;
    }
    foreach (['default_popup', 'page', 'service_worker', 'default_page'] as $key) {
        if (isset($manifest[$section][$key]) && is_string($manifest[$section][$key])) {
            $manifest[$section][$key] = $toDistRelative($manifest[$section][$key]);
        }
    }
}

// ---------------------------------------------------------------------------
// 先写出结果，再做文件存在性校验
//
// 顺序很重要：dist/manifest.json 可能因为上一次构建而残留着错误路径，
// 若先校验再写入，就会拿着旧文件报错（例如 dist/dist/newtab.html），
// 明明本次生成的 manifest 是好的。
// ---------------------------------------------------------------------------
$json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

foreach ($notes as $note) {
    fwrite(STDOUT, "  · " . $note . "\n");
}

if (!$checkOnly) {
    if (!is_dir($distDir) && !@mkdir($distDir, 0775, true) && !is_dir($distDir)) {
        fwrite(STDERR, "无法创建 dist/ 目录\n");
        exit(1);
    }

    if (@file_put_contents($target, $json) === false) {
        fwrite(STDERR, "无法写入 dist/manifest.json\n");
        exit(1);
    }
}

// ---------------------------------------------------------------------------
// 校验
// ---------------------------------------------------------------------------
if (($manifest['manifest_version'] ?? 0) !== 3) {
    $errors[] = 'manifest_version 必须为 3';
}

$referenced = [];

if (isset($manifest['chrome_url_overrides']['newtab'])) {
    $referenced['chrome_url_overrides.newtab'] = (string) $manifest['chrome_url_overrides']['newtab'];
}

foreach (($manifest['icons'] ?? []) as $size => $path) {
    if (is_string($path)) {
        $referenced['icons.' . $size] = $path;
    }
}

foreach (['action', 'options_ui', 'background'] as $section) {
    foreach (['default_popup', 'page', 'service_worker'] as $key) {
        if (isset($manifest[$section][$key]) && is_string($manifest[$section][$key])) {
            $referenced[$section . '.' . $key] = $manifest[$section][$key];
        }
    }
}

if (!isset($manifest['chrome_settings_overrides']['homepage'])) {
    $notes[] = 'chrome_settings_overrides.homepage 未设置（可选；新标签页接管已由 chrome_url_overrides.newtab 完成）';
}

foreach ($referenced as $label => $relPath) {
    if (preg_match('#^[a-zA-Z][\w+.-]*:#', $relPath) === 1 || str_starts_with($relPath, '/')) {
        continue;
    }

    $full = $distDir . '/' . $relPath;
    if (!is_file($full)) {
        $errors[] = sprintf('%s 指向的文件不存在：dist/%s', $label, $relPath);
    }
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, "  \033[31m✗\033[0m " . $error . "\n");
    }
    fwrite(STDERR, "\nmanifest 校验未通过（" . count($errors) . " 个问题）\n");
    exit(1);
}

foreach ($notes as $note) {
    fwrite(STDOUT, "  · " . $note . "\n");
}

fwrite(STDOUT, sprintf(
    "  \033[32m✓\033[0m %s（manifest_version 3，%d 个引用文件均存在）\n",
    $checkOnly ? 'manifest 校验通过' : 'dist/manifest.json 已生成',
    count($referenced)
));

