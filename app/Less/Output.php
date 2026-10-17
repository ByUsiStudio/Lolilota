<?php

declare(strict_types=1);

namespace Lolilota\Less;

/**
 * CSS 输出缓冲
 *
 * 负责缩进与花括号配平。设计成「先声明后包裹」的顺序：
 * Compiler 进入规则时调用 openRule()，写完声明再 closeRule()，
 * 因此这里不需要理解 Less，只需要把行拼好。
 */
final class Output
{
    /** @var list<string> */
    private array $lines = [];

    private int $depth = 0;
    private bool $pendingOpen = false;

    /** @var list<string> */
    private array $openAtRules = [];

    private string $indent = '    ';

    /**
     * 打开一个选择器块
     *
     * @param list<string> $selectors
     * @param list<string> $atWrappers 外层 at 规则（如 @media ...）
     */
    public function openRule(array $selectors, array $atWrappers): void
    {
        $this->closeStale($atWrappers);

        $this->push($this->indent($this->depth) . implode(",\n" . $this->indent($this->depth), $selectors) . ' {');
        $this->depth++;
    }

    public function closeRule(): void
    {
        $this->depth = max(0, $this->depth - 1);
        // 空规则直接丢弃（Less 也不会为它产出内容）
        if ($this->pendingOpen) {
            $this->pendingOpen = false;
            return;
        }
        $this->push($this->indent($this->depth) . '}');
    }

    public function declaration(string $property, string $value): void
    {
        $property = trim($property);
        $value = trim($value);

        if ($property === '' || $value === '') {
            return;
        }

        $this->pendingOpen = false;
        $this->push($this->indent($this->depth) . $property . ': ' . $value . ';');
    }

    public function openAtRule(string $prelude): void
    {
        $this->push($this->indent($this->depth) . trim($prelude) . ' {');
        $this->depth++;
        $this->openAtRules[] = $prelude;
    }

    public function closeAtRule(): void
    {
        $this->depth = max(0, $this->depth - 1);
        array_pop($this->openAtRules);
        $this->push($this->indent($this->depth) . '}');
    }

    /** 原始行（用于注释等） */
    public function raw(string $line): void
    {
        $this->push($line);
    }

    /**
     * 关闭已经不再需要的 at 规则包装
     *
     * @param list<string> $wanted
     */
    private function closeStale(array $wanted): void
    {
        while ($this->openAtRules !== [] && !in_array(end($this->openAtRules), $wanted, true)) {
            $this->closeAtRule();
        }

        foreach ($wanted as $wrapper) {
            if (!in_array($wrapper, $this->openAtRules, true)) {
                $this->openAtRule($wrapper);
            }
        }
    }

    private function push(string $line): void
    {
        $this->lines[] = $line;
    }

    private function indent(int $depth): string
    {
        return str_repeat($this->indent, max(0, $depth));
    }

    public function toString(): string
    {
        // 收尾：把没关的块补齐
        while ($this->depth > 0) {
            $this->depth--;
            $this->lines[] = $this->indent($this->depth) . '}';
        }
        while ($this->openAtRules !== []) {
            array_pop($this->openAtRules);
        }

        return rtrim(implode("\n", $this->lines)) . "\n";
    }
}
