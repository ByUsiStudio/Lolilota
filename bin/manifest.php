#!/usr/bin/env php
<?php
/**
 * Lolilota 扩展清单：生成两个位置的 manifest.json 并校验（纯 PHP，不依赖 Node.js）
 *
 * 为什么要生成两份：
 *   浏览器扩展加载的是一个目录，manifest 里的相对路径都相对该目录解析。
 *   本项目的源码都在 src/，构建产物在 dist/，用户有两种加载方式：
 *
 *     A. 加载 dist/           -> dist/manifest.json，路径形如  icons/icon16.png
 *     B. 加载仓库根目录        -> manifest.json，路径形如       dist/icons/icon16.png
 *
 *   因此以「dist 基准」为唯一事实来源（manifest.json 里的写法），
 *   再由本脚本派生出仓库根那一份。
 *
 * 校验内容：
 *   - manifest_version 必须是 3
 *   - chrome_url_overrides.newtab 指向的文件必须存在
 *   - icons 指向的每个文件必须存在
 *   - chrome_settings_overrides.homepage 必须是绝对 URL，
 *     否则从 dist/manifest.json 中移除（Chrome 会拒绝加载相对路径）
 *
 * 用法：
 *   php bin/manifest.php            生成两份并校验
 *   php bin/manifest.php --check    只校验 dist/manifest.json 是否最新
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "本脚本只能在命令行运行\n");
    exit(1);
}

require dirname(__DIR__) . '/public/bootstrap.php';

$args = array_slice($argv, 1);
$checkOnly = in_array('--check', $args, true);

$rootManifest = LOLILOTA_ROOT . '/manifest.json';
$distDir = LOLILOTA_ROOT . '/dist';
$distManifest = $distDir . '/manifest.json';

// ---------------------------------------------------------------------------
// 读取（manifest.json 以 dist 基准书写，这份即 dist 版本）
// ---------------------------------------------------------------------------
$raw = (string) file_get_contents($rootManifest);
$distVersion = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw, true);

if (!is_array($distVersion)) {
    fwrite(STDERR, "manifest.json 不是合法 JSON：" . json_last_error_msg() . "\n");
    exit(1);
}

$notes = [];

// homepage 必须是绝对 URL，否则从扩展里移除（它也只对 Firefox 生效）
if (isset($distVersion['chrome_settings_overrides']['homepage'])) {
    $homepage = (string) $distVersion['chrome_settings_overrides']['homepage'];

    if (preg_match('#^https?://#i', $homepage) !== 1) {
        $notes[] = sprintf(
            'chrome_settings_overrides.homepage 不是绝对 URL（"%s"），已移除'
            . '（Chrome 只接受绝对 URL；新标签页接管由 chrome_url_overrides.newtab 完成）',
            $homepage
        );
        unset($distVersion['chrome_settings_overrides']['homepage']);
    }
}

if (($distVersion['chrome_settings_overrides'] ?? []) === []) {
    unset($distVersion['chrome_settings_overrides']);
}

// ---------------------------------------------------------------------------
// 派生「仓库根」版本：给相对路径补上 dist/ 前缀
// ---------------------------------------------------------------------------
$rootVersion = $distVersion;

/** 绝对 URL / data URL / 以 / 开头的不处理 */
$isAbsolute = static fn (string $path): bool =>
    preg_match('#^[a-zA-Z][\w+.-]*:#', $path) === 1 || str_starts_with($path, '//') || str_starts_with($path, '/');

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

// ---------------------------------------------------------------------------
// 校验 dist 版本（扩展真正加载的那一份）
// ---------------------------------------------------------------------------
$errors = [];

if (($distVersion['manifest_version'] ?? 0) !== 3) {
    $errors[] = 'manifest_version 必须为 3';
}

$referenced = [];
if (isset($distVersion['chrome_url_overrides']['newtab'])) {
    $referenced['chrome_url_overrides.newtab'] = (string) $distVersion['chrome_url_overrides']['newtab'];
}
foreach (($distVersion['icons'] ?? []) as $size => $path) {
    if (is_string($path)) {
        $referenced['icons.' . $size] = $path;
    }
}
foreach (['action', 'options_ui', 'background'] as $section) {
    foreach (['default_popup', 'page', 'service_worker'] as $key) {
        if (isset($distVersion[$section][$key]) && is_string($distVersion[$section][$key])) {
            $referenced[$section . '.' . $key] = $distVersion[$section][$key];
        }
    }
}

$requireFile = static function (string $label, string $relPath) use ($distDir, &$errors): void {
    if (preg_match('#^[a-zA-Z][\w+.-]*:#', $relPath) === 1 || str_starts_with($relPath, '/')) {
        return;
    }
    if (!is_file($distDir . '/' . $relPath)) {
        $errors[] = sprintf('%s 指向的文件不存在：dist/%s', $label, $relPath);
    }
};

foreach ($referenced as $label => $relPath) {
    $requireFile($label, (string) $relPath);
}

// 仓库根版本也要能落地（dist/... 是否真的存在）
foreach (($rootVersion['const_placeholder'] ?? []) as $ignored) {
    // 占位，保持结构清晰
}
foreach ($rootVersion['icons'] ?? [] as $size => $path) {
    if (is_string($path) && str_starts_with($path, 'dist/')) {
        if (!is_file(LOLILOTA_ROOT . '/' . $path)) {
            $errors[] = sprintf('根 manifest 的 icons.%s 指向的文件不存在：%s', $size, $path);
        }
    }
}

// ---------------------------------------------------------------------------
// 输出
// ---------------------------------------------------------------------------
foreach ($notes as $note) {
    fwrite(STDOUT, "  · " . $note . "\n");
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, "  \033[31m✗\033[0m " . $error . "\n");
    }
    fwrite(STDERR, "\nmanifest 校验未通过（" . count($errors) . " 个问题）\n");
    exit(1);
}

$encode = static fn (array $data): string =>
    json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

$distJson = $encode($distVersion);
$rootJson = $encode($rootVersion);

if ($checkOnly) {
    $ok = is_file($distManifest) && file_get_contents($distManifest) === $distJson;
    if (!$ok) {
        fwrite(STDERR, "  \033[33m•\033[0m dist/manifest.json 需要重新生成\n");
        exit(1);
    }
    fwrite(STDOUT, "  \033[32m✓\033[0m manifest 已是最新（"
        . count($referenced) . " 个引用文件均存在）\n");
    exit(0);
}

if (!is_dir($distDir) && !@mkdir($distDir, 0775, true) && !is_dir($distDir)) {
    fwrite(STDERR, "无法创建 dist/ 目录\n");
    exit(1);
}

if (@file_put_contents($distManifest, $distJson) === false) {
    fwrite(STDERR, "无法写入 dist/manifest.json\n");
    exit(1);
}

if (@file_put_contents($rootManifest, $rootJson) === false) {
    fwrite(STDERR, "无法写入 manifest.json\n");
    exit(1);
}

fwrite(STDOUT, sprintf(
    "  \033[32m✓\033[0m 已生成 manifest.json（根目录加载用）与 dist/manifest.json（dist 加载用）\n"
    . "      校验通过：manifest_version 3，%d 个引用文件均存在\n",
    count($referenced)
));
