<?php

declare(strict_types=1);

namespace Lolilota\Less;

/**
 * Less 语法解析器
 *
 * 只做结构性解析，产出一棵简单的树：
 *
 *   ['kind' => 'decl',   'selector' => '颜色',    'value' => '#fff', 'line' => 12]
 *   ['kind' => 'rule',   'selector' => '&:hover', 'body' => [...],   'line' => 30]
 *   ['kind' => 'atrule', 'selector' => '@media (…)', 'body' => [...], 'line' => 6]
 *   ['kind' => 'import', 'selector' => '"x.less"', 'line' => 19]
 *
 * 语义判断（变量 / 混入 / 规则）留给 Compiler，这里不猜。
 */
final class Parser
{
    private string $source;
    private string $file;
    private int $pos = 0;
    private int $length;
    private int $line = 1;

    public function __construct(string $source, string $file = '<string>')
    {
        $this->source = $source;
        $this->file = $file;
        $this->length = strlen($source);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function parse(): array
    {
        return $this->parseStatements(false);
    }

    // -----------------------------------------------------------------
    // 主循环
    // -----------------------------------------------------------------

    /**
     * @param bool $insideBlock 是否在 {} 内部（内部遇到 } 结束）
     * @return list<array<string, mixed>>
     */
    private function parseStatements(bool $insideBlock): array
    {
        $nodes = [];

        while (true) {
            $this->skipTrivia();

            if ($this->eof()) {
                if ($insideBlock) {
                    throw $this->error('缺少与之匹配的 }');
                }
                break;
            }

            if ($this->peek() === '}') {
                if (!$insideBlock) {
                    throw $this->error('出现了多余的 }');
                }
                $this->pos++;
                $this->line++;
                break;
            }

            if ($this->peek() === ';') {
                $this->pos++;
                continue;
            }

            $node = $this->parseStatement();
            if ($node !== null) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /**
     * 解析一条语句：要么是「前缀 + 块」，要么是「前缀 + 分号结尾」
     *
     * @return array<string, mixed>|null
     */
    private function parseStatement(): ?array
    {
        $startLine = $this->line;
        $prefix = $this->readUntil(['{', ';', '}']);

        $terminator = $this->eof() ? '' : $this->peek();
        $text = trim($prefix);

        // ---- 以 ; 结尾：声明 / 变量 / 混入调用 / @import ----
        if ($terminator === ';') {
            $this->pos++;

            if ($text === '') {
                return null;
            }

            if (preg_match('/^@import\b(.*)$/is', $text, $m) === 1) {
                return [
                    'kind'     => 'import',
                    'selector' => trim($m[1]),
                    'line'     => $startLine,
                    'file'     => $this->file,
                ];
            }

            $colon = $this->findTopLevelColon($text);
            if ($colon === null) {
                // 没有冒号的裸语句（例如 .mixin()）——交给 Compiler 判断
                return [
                    'kind'     => 'decl',
                    'selector' => $text,
                    'value'    => '',
                    'line'     => $startLine,
                    'file'     => $this->file,
                ];
            }

            return [
                'kind'     => 'decl',
                'selector' => trim(substr($text, 0, $colon)),
                'value'    => trim(substr($text, $colon + 1)),
                'line'     => $startLine,
                'file'     => $this->file,
            ];
        }

        // ---- 以 { 开头：规则 / 混入定义 / at 规则 ----
        if ($terminator === '{') {
            $this->pos++;
            $this->line++;

            $body = $this->parseStatements(true);

            if ($text === '') {
                throw $this->error('出现了没有选择器的 { }（第 ' . $startLine . ' 行）');
            }

            if (str_starts_with($text, '@') && !str_starts_with($text, '@{')) {
                return [
                    'kind'     => 'atrule',
                    'selector' => $text,
                    'body'     => $body,
                    'line'     => $startLine,
                    'file'     => $this->file,
                ];
            }

            return [
                'kind'     => 'rule',
                'selector' => $text,
                'body'     => $body,
                'line'     => $startLine,
                'file'     => $this->file,
            ];
        }

        // ---- 以 } 结尾：块内最后一条语句漏了分号 ----
        if ($terminator === '}') {
            if ($text === '') {
                return null;
            }

            $colon = $this->findTopLevelColon($text);
            if ($colon === null) {
                return [
                    'kind'     => 'decl',
                    'selector' => $text,
                    'value'    => '',
                    'line'     => $startLine,
                    'file'     => $this->file,
                ];
            }

            return [
                'kind'     => 'decl',
                'selector' => trim(substr($text, 0, $colon)),
                'value'    => trim(substr($text, $colon + 1)),
                'line'     => $startLine,
                'file'     => $this->file,
            ];
        }

        // ---- 到文件末尾了 ----
        if ($text !== '') {
            $colon = $this->findTopLevelColon($text);
            if ($colon === null) {
                throw $this->error('文件末尾有无法解析的语句：' . $text);
            }

            return [
                'kind'     => 'decl',
                'selector' => trim(substr($text, 0, $colon)),
                'value'    => trim(substr($text, $colon + 1)),
                'line'     => $startLine,
                'file'     => $this->file,
            ];
        }

        return null;
    }

    // -----------------------------------------------------------------
    // 词法辅助
    // -----------------------------------------------------------------

    /**
     * 读取直到遇到任一终止符（跳过括号、引号、注释内的终止符）
     */
    private function readUntil(array $terminators): string
    {
        $buffer = '';
        $depth = 0;
        $quote = null;
        $bracket = 0;

        while (!$this->eof()) {
            $char = $this->source[$this->pos];

            // 注释
            if ($quote === null && $char === '/' && $this->pos + 1 < $this->length) {
                $next = $this->source[$this->pos + 1];
                if ($next === '/') {
                    $this->skipLineComment();
                    $buffer .= ' ';
                    continue;
                }
                if ($next === '*') {
                    $this->skipBlockComment();
                    $buffer .= ' ';
                    continue;
                }
            }

            if ($quote !== null) {
                $buffer .= $char;
                if ($char === $quote && ($this->pos === 0 || $this->source[$this->pos - 1] !== '\\')) {
                    $quote = null;
                }
                $this->advance();
                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
                $buffer .= $char;
                $this->advance();
                continue;
            }

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($char === '[') {
                $bracket++;
            } elseif ($char === ']') {
                $bracket = max(0, $bracket - 1);
            }

            $atTop = $depth === 0 && $bracket === 0;

            if ($atTop && in_array($char, $terminators, true)) {
                break;
            }

            // { } 只有在不作为终止符时才计入结构
            if ($atTop && $char === '{' && !in_array('{', $terminators, true)) {
                break;
            }

            $buffer .= $char;
            $this->advance();
        }

        return $buffer;
    }

    /** 跳过空白与注释 */
    private function skipTrivia(): void
    {
        while (!$this->eof()) {
            $char = $this->source[$this->pos];

            if ($char === ' ' || $char === "\t" || $char === "\r" || $char === "\n") {
                $this->advance();
                continue;
            }

            if ($char === '/' && $this->pos + 1 < $this->length) {
                $next = $this->source[$this->pos + 1];
                if ($next === '/') {
                    $this->skipLineComment();
                    continue;
                }
                if ($next === '*') {
                    $this->skipBlockComment();
                    continue;
                }
            }

            break;
        }
    }

    private function skipLineComment(): void
    {
        while (!$this->eof() && $this->source[$this->pos] !== "\n") {
            $this->advance();
        }
    }

    private function skipBlockComment(): void
    {
        $this->pos += 2;
        while (!$this->eof()) {
            if ($this->source[$this->pos] === '*' && $this->pos + 1 < $this->length
                && $this->source[$this->pos + 1] === '/') {
                $this->pos += 2;
                return;
            }
            $this->advance();
        }
    }

    private function advance(): void
    {
        if ($this->source[$this->pos] === "\n") {
            $this->line++;
        }
        $this->pos++;
    }

    private function peek(): string
    {
        return $this->source[$this->pos];
    }

    private function eof(): bool
    {
        return $this->pos >= $this->length;
    }

    private function error(string $message): LessException
    {
        return new LessException($message . '（' . basename($this->file) . ':' . $this->line . '）');
    }

    /**
     * 找到处于顶层的冒号（跳过括号、方括号与引号内的内容）
     *
     * 用于区分「属性: 值」与「包含冒号的选择器」，例如
     *   background: url("data:image/svg+xml,...")   只认第一个冒号
     *   &[data-x="a:b"]:hover                        冒号在选择器里，不参与判断
     */
    private function findTopLevelColon(string $text): ?int
    {
        $depth = 0;
        $bracket = 0;
        $quote = null;
        $length = strlen($text);

        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];

            if ($quote !== null) {
                if ($char === $quote && ($i === 0 || $text[$i - 1] !== '\\')) {
                    $quote = null;
                }
                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
                continue;
            }

            if ($char === '(') {
                $depth++;
                continue;
            }
            if ($char === ')') {
                $depth = max(0, $depth - 1);
                continue;
            }
            if ($char === '[') {
                $bracket++;
                continue;
            }
            if ($char === ']') {
                $bracket = max(0, $bracket - 1);
                continue;
            }

            if ($char === ':' && $depth === 0 && $bracket === 0) {
                // 伪类选择器（:hover）不是声明：要求冒号前是合法属性名
                $before = trim(substr($text, 0, $i));
                if (preg_match('/^[-a-zA-Z_][\w-]*$/', $before) === 1) {
                    return $i;
                }
            }
        }

        return null;
    }
}
