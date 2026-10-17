<?php

declare(strict_types=1);

namespace Lolilota\Less;

/**
 * CSS 输出缓冲
 *
 * 负责缩进与花括号配平。调用约定是「先声明后包裹」：
 *   openRule() -> declaration()* -> closeRule()
 *
 * 关于 at 规则（@media / @supports）：同一段样式要在同一个 @media 块里输出，
 * 但 Less 的嵌套规则是被逐个展开的，所以这里提供 syncAtRules()，
 * 由 Compiler 在渲染每条规则前调用，声明「这条规则所属的 at 规则链」，
 * Output 负责关掉不再需要的、打开新出现的。空 @media 块会被自动丢弃。
 *
 * 未加 final 是为了便于测试时用子类追踪调用序列。
 */
class Output
{
    /** @var list<string> */
    protected array $lines = [];

    protected int $depth = 0;

    /** @var list<string> 当前链路上打开的 at 规则原文 */
    protected array $openAtRules = [];

    /** @var list<int> 每个 at 规则原文在 lines 里的下标，用于丢弃空块 */
    protected array $openAtRuleLines = [];

    /** @var list<bool> 对应 at 规则块内是否已有内容 */
    protected array $openAtRuleFilled = [];

    protected string $indent = '    ';

    /**
     * 同步 at 规则链
     *
     * @param list<string> $wanted 当前规则应处的 at 规则链（由外到内）
     */
    public function syncAtRules(array $wanted): void
    {
        // 关掉多余的（从最内层开始）
        while ($this->openAtRules !== []
            && !in_array(end($this->openAtRules), $wanted, true)) {
            $this->popAtRule();
        }

        // 打开缺少的
        foreach ($wanted as $wrapper) {
            if (!in_array($wrapper, $this->openAtRules, true)) {
                $this->pushAtRule($wrapper);
            }
        }
    }

    /**
     * 打开一个选择器块
     *
     * @param list<string> $selectors
     */
    public function openRule(array $selectors): void
    {
        $this->markFilled();
        $this->push($this->indent($this->depth) . implode(",\n" . $this->indent($this->depth), $selectors) . ' {');
        $this->depth++;
    }

    public function closeRule(): void
    {
        $this->depth = max(0, $this->depth - 1);
        $this->push($this->indent($this->depth) . '}');
    }

    public function declaration(string $property, string $value): void
    {
        $property = trim($property);
        $value = trim($value);

        if ($property === '' || $value === '') {
            return;
        }

        $this->markFilled();
        $this->push($this->indent($this->depth) . $property . ': ' . $value . ';');
    }

    /** 原始行 */
    public function raw(string $line): void
    {
        $this->push($line);
    }

    // -----------------------------------------------------------------
    // @keyframes：自成一块，内部用百分比选择器，不参与 at 规则链
    // -----------------------------------------------------------------

    public function openKeyframes(string $prelude): void
    {
        $this->push($this->indent($this->depth) . trim($prelude) . ' {');
        $this->depth++;
    }

    public function closeKeyframes(): void
    {
        $this->depth = max(0, $this->depth - 1);
        $this->push($this->indent($this->depth) . '}');
    }

    public function openFrame(string $selector): void
    {
        $this->push($this->indent($this->depth) . trim($selector) . ' {');
        $this->depth++;
    }

    public function closeFrame(): void
    {
        $this->depth = max(0, $this->depth - 1);
        $this->push($this->indent($this->depth) . '}');
    }

    // -----------------------------------------------------------------
    // at 规则栈
    // -----------------------------------------------------------------

    private function pushAtRule(string $prelude): void
    {
        $this->openAtRuleLines[] = count($this->lines);
        $this->openAtRuleFilled[] = false;
        $this->push($this->indent($this->depth) . trim($prelude) . ' {');
        $this->depth++;
        $this->openAtRules[] = $prelude;
    }

    private function popAtRule(): void
    {
        $lineIndex = array_pop($this->openAtRuleLines);
        $filled = array_pop($this->openAtRuleFilled);
        array_pop($this->openAtRules);

        $this->depth = max(0, $this->depth - 1);

        if ($filled) {
            $this->push($this->indent($this->depth) . '}');
            return;
        }

        // 空块：把开头那行一起丢掉，不留空 @media
        if ($lineIndex !== null && isset($this->lines[$lineIndex])) {
            array_splice($this->lines, $lineIndex, 1);
        }
    }

    /** 标记当前所处的所有 at 规则块已有内容 */
    private function markFilled(): void
    {
        $count = count($this->openAtRuleFilled);
        for ($i = 0; $i < $count; $i++) {
            $this->openAtRuleFilled[$i] = true;
        }
    }

    private function push(string $line): void
    {
        $this->lines[] = $line;
    }

    protected function indent(int $depth): string
    {
        return str_repeat($this->indent, max(0, $depth));
    }

    /** 当前已写出的 CSS 文本（未收尾），供测试与调试使用 */
    public function currentText(): string
    {
        return implode("\n", $this->lines);
    }

    /** 是否还没写出任何内容 */
    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    public function toString(): string
    {
        while ($this->openAtRules !== []) {
            $this->popAtRule();
        }

        while ($this->depth > 0) {
            $this->depth--;
            $this->lines[] = $this->indent($this->depth) . '}';
        }

        return rtrim(implode("\n", $this->lines)) . "\n";
    }
}
