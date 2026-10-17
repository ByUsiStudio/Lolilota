#!/usr/bin/env php
<?php
/**
 * Lolilota 扩展清单：生成加载用的副本并校验（纯 PHP，不依赖 Node.js）
 *
 * ── 文件关系（重要）────────────────────────────────────────────────
 *   manifest.json                 事实来源。以 **dist 为基准** 书写路径
 *                                 （icons/icon16.png、newtab.html）。
 *                                 扩展加载 dist/ 目录时直接用这一份。
 *
 *   dist/manifest.json            由本脚本生成的副本，内容与上面一致。
 *                                 （浏览器加载 dist/ 时读的就是它）
 *
 *   manifest.root.json            可选副本：把路径加上 dist/ 前缀，
 *                                 便于「加载仓库根目录」这种用法。
 *                                 它不被 manifest.json 反向读取，
 *                                 因此不会出现前缀反复叠加的问题。
 * ──────────────────────────────────────────────────────────────────
 *
 * 校验内容：
 *   - manifest_version 必须是 3
 *   - chrome_url_overrides.newtab 与 icons 指向的文件必须存在
 *   - chrome_settings_overrides.homepage 必须是绝对 URL；
 *     相对路径会被移除（Chrome 会报
 *     "Invalid value for overriding homepage url" 并拒绝加载扩展）
 *
 * 用法：
 *   php bin/manifest.php            生成副本并校验
 *   php bin/manifest.php --check    只校验是否最新
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "本脚本只能在命令行运行\n");
    exit(1);
}

require dirname(__DIR__) . '/public/bootstrap.php';

$args = array_slice($argv, 1);
$checkOnly = in_array('--check', $args, true);

$source = LOLILOTA_ROOT . '/manifest.json';           // 事实来源（dist 基准）
$distDir = LOLILOTA_ROOT . '/dist';
$distManifest = $distDir . '/manifest.json';          // dist 加载用副本
$rootManifest = LOLILOTA_ROOT . '/manifest.root.json'; // 仓库根加载用副本（可选）

// ---------------------------------------------------------------------------
// 读取事实来源
// ---------------------------------------------------------------------------
if (!is_file($source)) {
    fwrite(STDERR, "找不到 manifest.json\n");
    exit(1);
}

$raw = (string) file_get_contents($source);
$manifest = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw, true);

if (!is_array($manifest)) {
    fwrite(STDERR, "\033[31m✗\033[0m manifest.json 不是合法 JSON：" . json_last_error_msg() . "\n");
    exit(1);
}

$notes = [];
$errors = [];

// ---------------------------------------------------------------------------
// homepage：从本地配置读取（仓库里不保存真实服务器地址）
//
// 来源优先级：
//   1. config/local.php 的 app.homepage
//   2. 环境变量 APP_HOMEPAGE
//   3. manifest.json 里已写的 chrome_settings_overrides.homepage（若为绝对 URL）
//
// 必须是绝对 URL —— 相对路径会让 Chrome 报
// "Invalid value for overriding homepage url" 并拒绝加载整个扩展。
// ---------------------------------------------------------------------------
// 注意：这里用完全限定名 \Lolilota\Core\Config，而不是 use 引入 ——
// config/config.php 返回数组时会占用 $config 变量名，用别名容易混淆。
$homepage = trim((string) \Lolilota\Core\Config::get('app.homepage', ''));

if ($homepage === '' && isset($manifest['chrome_settings_overrides']['homepage'])) {
    $existing = trim((string) $manifest['chrome_settings_overrides']['homepage']);
    if (preg_match('#^https?://#i', $existing) === 1) {
        $homepage = $existing;
    }
}

if ($homepage !== '') {
    if (preg_match('#^https?://#i', $homepage) !== 1) {
        $errors[] = sprintf(
            'app.homepage 必须是绝对 URL（当前为 "%s"）。'
            . 'Chrome 会报 "Invalid value for overriding homepage url" 并拒绝加载扩展。',
            $homepage
        );
    } else {
        $manifest['chrome_settings_overrides'] = ['homepage' => $homepage];
    }
} else {
    // 没配置就不声明 homepage：新标签页接管由 chrome_url_overrides.newtab 完成，
    // 不写这个字段不影响功能，也避免把无效值提交进仓库。
    unset($manifest['chrome_settings_overrides']);
    $notes[] = '未配置主页（config/local.php 的 app.homepage）；扩展不声明 homepage，'
        . '新标签页接管不受影响';
}

// manifest.json 里不允许出现需要部署时才确定的地址：写进来源文件会导致
// 每次构建都把同一个域名提交进仓库，因此这里是硬性检查。
if (isset($manifest['chrome_settings_overrides']['homepage'])) {
    $notes[] = 'homepage 来自本地配置，不会写入 manifest.json 源文件';
}

// ---------------------------------------------------------------------------
// 校验事实来源
// ---------------------------------------------------------------------------
if (($manifest['manifest_version'] ?? 0) !== 3) {
    $errors[] = 'manifest_version 必须为 3';
}

/** 绝对 URL / data URL / 以 / 开头的路径无需检查文件存在性 */
$isAbsolute = static fn (string $p): bool =>
    preg_match('#^[a-zA-Z][\w+.-]*:#', $p) === 1 || str_starts_with($p, '//') || str_starts_with($p, '/');

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

foreach ($referenced as $label => $relPath) {
    if ($isAbsolute($relPath)) {
        continue;
    }

    // 事实来源以 dist 为基准，所以文件应存在于 dist/ 下
    if (!is_file($distDir . '/' . $relPath)) {
        $errors[] = sprintf(
            '%s 指向的文件不存在：dist/%s（manifest.json 里的路径以 dist 为基准）',
            $label,
            $relPath
        );
    }

    // 前缀叠加的防呆：事实来源里不应该出现 dist/ 前缀
    if (str_starts_with($relPath, 'dist/')) {
        $errors[] = sprintf(
            '%s 的路径 "%s" 带了 dist/ 前缀。manifest.json 必须写成以 dist 为基准的相对路径'
            . '（如 %s），否则会出现 dist/dist/... 的错误路径。',
            $label,
            $relPath,
            substr($relPath, 5)
        );
    }
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, "  \033[31m✗\033[0m " . $error . "\n");
    }
    fwrite(STDERR, "\n\033[31mmanifest 校验未通过（" . count($errors) . " 个问题）\033[0m\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// 派生两个副本
// ---------------------------------------------------------------------------
$encode = static fn (array $data): string =>
    json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

// dist 副本：与事实来源完全一致
$distJson = $encode($manifest);

// 仓库根副本：相对路径补 dist/ 前缀
$rootVersion = $manifest;
foreach ($rootVersion['icons'] ?? [] as $size => $path) {
    if (is_string($path) && !$isAbsolute($path)) {
        $rootVersion['icons'][$size] = 'dist/' . $path;
    }
}
foreach ($rootVersion['chrome_url_overrides'] ?? [] as $key => $path) {
    if (is_string($path) && !$isAbsolute($path)) {
        $rootVersion['chrome_url_overrides'][$key] = 'dist/' . $path;
    }
}
$rootJson = $encode($rootVersion);

// ---------------------------------------------------------------------------
// 写出
// ---------------------------------------------------------------------------
if ($checkOnly) {
    $distOk = is_file($distManifest) && file_get_contents($distManifest) === $distJson;
    $rootOk = is_file($rootManifest) && file_get_contents($rootManifest) === $rootJson;

    if (!$distOk || !$rootOk) {
        fwrite(STDERR, "  \033[33m•\033[0m 扩展清单副本需要重新生成\n");
        exit(1);
    }

    fwrite(STDOUT, "  \033[32m✓\033[0m 扩展清单已最新（"
        . count($referenced) . " 个引用文件均存在）\n");
    exit(0);
}

foreach ($notes as $note) {
    fwrite(STDOUT, "  · " . $note . "\n");
}

if (!is_dir($distDir) && !@mkdir($distDir, 0775, true) && !is_dir($distDir)) {
    fwrite(STDERR, "无法创建 dist/ 目录\n");
    exit(1);
}

foreach ([[$distManifest, $distJson, 'dist/manifest.json'], [$rootManifest, $rootJson, 'manifest.root.json']] as [$file, $json, $label]) {
    if (@file_put_contents($file, $json) === false) {
        fwrite(STDERR, "无法写入 {$label}\n");
        exit(1);
    }
}

fwrite(STDOUT, sprintf(
    "  \033[32m✓\033[0m 扩展清单已生成：dist/manifest.json（加载 dist 用）、"
    . "manifest.root.json（加载仓库根用）\n"
    . "      manifest_version 3，%d 个引用文件均存在\n",
    count($referenced)
));
