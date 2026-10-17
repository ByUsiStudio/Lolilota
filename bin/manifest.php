#!/usr/bin/env php
<?php
/**
 * Lolilota 扩展清单：生成并校验 dist/manifest.json（纯 PHP，不依赖 Node.js）
 *
 * ── 文件关系 ───────────────────────────────────────────────────────
 *   manifest.json         事实来源。以 **dist 为基准** 书写路径
 *                         （icons/icon16.png、newtab.html）。
 *                         不含任何需要部署时才确定的地址：
 *                         主页地址来自 config/local.php 的 app.homepage。
 *
 *   dist/manifest.json    由本脚本生成的加载用清单（浏览器实际读的那份）。
 *                         homepage 也从本地配置注入，因此来源文件里
 *                         永远不会出现真实域名。
 * ──────────────────────────────────────────────────────────────────
 *
 * 扩展加载方式只有一种：**加载 dist/ 目录**。
 * 仓库根目录不能当扩展加载：一个目录里只能有一个 manifest.json，
 * 而 dist/ 才是自包含的扩展包。
 *
 * 校验内容：
 *   - manifest_version 必须是 3
 *   - chrome_url_overrides.newtab 与 icons 指向的文件必须存在
 *   - homepage（若配置）必须是绝对 URL，否则报错并终止
 *
 * 用法：
 *   php bin/manifest.php            生成并校验
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

$source = LOLILOTA_ROOT . '/manifest.json';   // 事实来源（dist 基准，无真实地址）
$distDir = LOLILOTA_ROOT . '/dist';
$distManifest = $distDir . '/manifest.json';  // 加载用清单

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
// homepage：从本地配置读取
//
// 来源优先级：
//   1. config/local.php 的 app.homepage（或环境变量 APP_HOMEPAGE）
//   2. manifest.json 里已写的 chrome_settings_overrides.homepage（若为绝对 URL）
//
// 必须是绝对 URL —— 相对路径会让 Chrome 报
// "Invalid value for overriding homepage url" 并拒绝加载整个扩展。
// 没配置就不声明该字段：新标签页接管由 chrome_url_overrides.newtab 负责。
//
// 注意：这里用完全限定名 \Lolilota\Core\Config，而不是 use 引入 ——
// config/config.php 返回数组时会占用 $config 变量名，用别名容易混淆。
// ---------------------------------------------------------------------------
$homepage = trim((string) \Lolilota\Core\Config::get('app.homepage', ''));

if ($homepage === '' && isset($manifest['chrome_settings_overrides']['homepage'])) {
    $existing = trim((string) $manifest['chrome_settings_overrides']['homepage']);
    if (preg_match('#^https?://#i', $existing) === 1) {
        $homepage = $existing;
    }
}

if ($homepage === '') {
    unset($manifest['chrome_settings_overrides']);
    $notes[] = '未配置主页（config/local.php 的 app.homepage 或环境变量 APP_HOMEPAGE）；'
        . '扩展不声明 homepage，新标签页接管不受影响';
} elseif (preg_match('#^https?://#i', $homepage) !== 1) {
    $errors[] = sprintf(
        'app.homepage 必须是绝对 URL（当前为 "%s"）。否则 Chrome 会报 '
        . '"Invalid value for overriding homepage url" 并拒绝加载扩展。',
        $homepage
    );
} else {
    $manifest['chrome_settings_overrides'] = ['homepage' => $homepage];
    $notes[] = 'homepage 来自本地配置，源文件 manifest.json 里不会出现该地址';
}

// ---------------------------------------------------------------------------
// 校验
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

    // 事实来源以 dist 为基准，文件应存在于 dist/ 下
    if (!is_file($distDir . '/' . $relPath)) {
        $errors[] = sprintf(
            '%s 指向的文件不存在：dist/%s（manifest.json 里的路径以 dist 为基准）',
            $label,
            $relPath
        );
    }

    // 前缀叠加的防呆
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
// 写出
// ---------------------------------------------------------------------------
$json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

if ($checkOnly) {
    $ok = is_file($distManifest) && file_get_contents($distManifest) === $json;

    if (!$ok) {
        fwrite(STDERR, "  \033[33m•\033[0m dist/manifest.json 需要重新生成（执行 php bin/manifest.php）\n");
        exit(1);
    }

    fwrite(STDOUT, "  \033[32m✓\033[0m dist/manifest.json 已是最新（"
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

if (@file_put_contents($distManifest, $json) === false) {
    fwrite(STDERR, "无法写入 dist/manifest.json\n");
    exit(1);
}

fwrite(STDOUT, sprintf(
    "  \033[32m✓\033[0m dist/manifest.json 已生成（manifest_version 3，%d 个引用文件均存在）\n"
    . "      扩展加载方式：选择 dist/ 目录\n",
    count($referenced)
));
