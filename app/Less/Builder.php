<?php

declare(strict_types=1);

namespace Lolilota\Less;

/**
 * 样式构建
 *
 * 把 src/less/style.less 编译成 css/lolilota.css 与 dist/css/lolilota.css，
 * 并写一份构建清单（源文件指纹），供开发模式判断是否需要重新编译。
 *
 * 不依赖 Node.js：整个流程只用 PHP + 本目录的极简 Less 编译器。
 */
final class Builder
{
    public const SOURCE_FILE = 'src/less/style.less';
    public const OUTPUT_FILE = 'css/lolilota.css';
    public const DIST_FILE = 'dist/css/lolilota.css';
    public const MANIFEST_FILE = 'var/cache/less-manifest.json';

    private string $root;

    public function __construct(?string $root = null)
    {
        $this->root = $root ?? dirname(__DIR__, 2);
    }

    /**
     * 编译并写出产物
     *
     * @param bool $minify 是否压缩
     * @return array{bytes:int, rules:int, gzip:int, files:list<string>, errors:list<string>}
     */
    public function build(bool $minify = true): array
    {
        $entry = $this->root . '/' . self::SOURCE_FILE;
        if (!is_file($entry)) {
            throw new LessException('找不到 Less 入口文件：' . self::SOURCE_FILE);
        }

        $css = (new Compiler([$this->root . '/src/less']))->compileFile($entry);

        if ($minify) {
            $css = $this->minify($css);
        }

        $banner = '/*! Lolilota | 由 src/less/style.less 编译（PHP 编译器），请勿直接修改本文件 */' . "\n";
        $payload = $banner . $css;

        $written = [];
        $errors = [];

        foreach ([self::OUTPUT_FILE, self::DIST_FILE] as $relative) {
            $target = $this->root . '/' . $relative;
            $dir = dirname($target);
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                $errors[] = '无法创建目录：' . $relative;
                continue;
            }

            if (@file_put_contents($target, $payload) === false) {
                $errors[] = '无法写入：' . $relative;
                continue;
            }

            $written[] = $relative;
        }

        $this->writeManifest($payload);

        return [
            'bytes'  => strlen($payload),
            'rules'  => substr_count($css, '{'),
            'gzip'   => strlen((string) gzencode($payload, 6)),
            'files'  => $written,
            'errors' => $errors,
        ];
    }

    /**
     * 源文件是否比产物新（用于开发模式按需编译）
     */
    public function isStale(): bool
    {
        $output = $this->root . '/' . self::OUTPUT_FILE;
        if (!is_file($output)) {
            return true;
        }

        $manifest = $this->readManifest();
        if ($manifest === null || ($manifest['hash'] ?? '') !== $this->sourceHash()) {
            return true;
        }

        return false;
    }

    /**
     * 开发模式：返回 CSS 内容，只写 var/cache，**不覆盖**仓库里的产物
     *
     * 这样一边开着开发模式改样式，一边也不会把未压缩的临时结果
     * 写进 css/ 与 dist/css/（那两个文件是要提交的发布产物）。
     */
    public function devCss(): string
    {
        $entry = $this->root . '/' . self::SOURCE_FILE;
        $css = (new Compiler([$this->root . '/src/less']))->compileFile($entry);
        $payload = '/*! Lolilota | 开发模式实时编译（PHP），未压缩 */' . "\n" . $css;

        $cacheDir = $this->root . '/var/cache';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0775, true);
        }

        @file_put_contents($cacheDir . '/lolilota.dev.css', $payload);
        @file_put_contents(
            $cacheDir . '/lolilota.dev.css.map.json',
            json_encode(['hash' => $this->sourceHash(), 'at' => time()], JSON_PRETTY_PRINT)
        );

        return $payload;
    }

    /**
     * 编译到文件，不做格式处理
     *
     * 供等价性测试等场景生成「与 Node/less 同为未压缩」的对照文件。
     */
    public function compileTo(string $target): int
    {
        $entry = $this->root . '/' . self::SOURCE_FILE;
        $css = (new Compiler([$this->root . '/src/less']))->compileFile($entry);

        $dir = dirname($target);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return (int) @file_put_contents($target, $css);
    }

    /** 开发缓存是否已失效（源文件有改动） */
    public function devCacheStale(): bool
    {
        $marker = $this->root . '/var/cache/lolilota.dev.css.map.json';
        if (!is_file($marker)) {
            return true;
        }

        $data = json_decode((string) file_get_contents($marker), true);
        if (!is_array($data) || !isset($data['hash'])) {
            return true;
        }

        return (string) $data['hash'] !== $this->sourceHash();
    }

    /** 读取开发缓存内容 */
    public function devCachedCss(): ?string
    {
        $file = $this->root . '/var/cache/lolilota.dev.css';
        if (!is_file($file)) {
            return null;
        }

        $css = (string) file_get_contents($file);
        return $css === '' ? null : $css;
    }

    // -----------------------------------------------------------------

    /** 所有 Less 源文件的整体指纹 */
    public function sourceHash(): string
    {
        $dir = $this->root . '/src/less';
        $files = $this->lessFiles($dir);
        sort($files);

        $parts = [];
        foreach ($files as $file) {
            $parts[] = str_replace($this->root, '', $file) . ':' . md5_file($file);
        }

        return md5(implode('|', $parts));
    }

    /** @return list<string> */
    private function lessFiles(string $dir): array
    {
        $out = [];
        if (!is_dir($dir)) {
            return $out;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $out = array_merge($out, $this->lessFiles($path));
            } elseif (str_ends_with($entry, '.less')) {
                $out[] = $path;
            }
        }

        return $out;
    }

    private function writeManifest(string $payload): void
    {
        $file = $this->root . '/' . self::MANIFEST_FILE;
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        @file_put_contents($file, json_encode([
            'hash'    => $this->sourceHash(),
            'built_at' => date('c'),
            'bytes'   => strlen($payload),
            'builder' => 'php',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /** @return array<string, mixed>|null */
    private function readManifest(): ?array
    {
        $file = $this->root . '/' . self::MANIFEST_FILE;
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    /**
     * 极简 CSS 压缩（与 Node 构建脚本保持同一策略）
     */
    private function minify(string $css): string
    {
        // 保留 /*! 开头的许可注释
        $css = preg_replace('#/\*(?!!)[\s\S]*?\*/#', '', $css) ?? $css;

        return str_replace(
            [';}' , "\n", '  '],
            ['}',  ' ',  ' '],
            preg_replace('/\s*([{}:;,>~+])\s*/', '$1', $css) ?? $css
        );
    }
}
