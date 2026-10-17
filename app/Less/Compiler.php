<?php

declare(strict_types=1);

namespace Lolilota\Less;

/**
 * 极简 Less 编译器
 *
 * 存在的意义：让本项目在**没有 Node.js** 的环境里也能编译样式。
 * 它不追求成为完整的 Less 实现，只精确覆盖本项目源码用到的特性：
 *
 *   1. 变量            @brand-700: #5a7ba8;      与使用 @brand-700
 *   2. @import         "x.less" / (reference) "x.less"，相对当前文件 + 搜索路径
 *   3. 嵌套规则        子选择器自动拼接父选择器
 *   4. 父选择器引用    &  /  &:hover  /  &.active
 *   5. 混入            定义 .glass(@a: .82) {} + 调用 .glass() / .glass(.9)
 *                      （仅无块调用的形式展开；带块的语句一律视为规则）
 *   6. 媒体查询        @media / @supports，支持嵌套
 *   7. 简单算式        480px * 3、2 * 12px（整段值都是算式时才计算）
 *   8. 注释            // 行注释与块注释都会被丢弃
 *
 * 刻意不支持（源码里也没有使用）：each()、extract()、@{} 插值、~"…" 转义、
 * 分离规则集、颜色函数。遇到无法处理的语法会抛异常并给出行号，
 * 而不是静默产出错误样式。
 *
 * 用法：
 *   $css = (new Compiler('src/less'))->compileFile('src/less/style.less');
 */
final class Compiler
{
    /** @var array<string, string> 变量名（含 @）=> 值 */
    private array $variables = [];

    /** @var array<string, list<array{params: string, body: array, line: int, file: string}>> */
    private array $mixins = [];

    /** @var list<string> import 搜索路径 */
    private array $paths = [];

    /** @var array<string, true> 已加载过的文件，避免重复展开 */
    private array $loaded = [];

    public function __construct(string|array $paths = [])
    {
        foreach ((array) $paths as $path) {
            $real = realpath($path);
            if ($real !== false) {
                $this->paths[] = $real;
            }
        }
    }

    /** 编译入口文件，返回 CSS 文本 */
    public function compileFile(string $entry): string
    {
        return $this->compileToOutput($entry)->toString();
    }

    /**
     * 编译到 Output 对象
     *
     * 供等价性测试等场景直接取用编译器产出的文本，避免再去解析生成的 CSS。
     */
    public function compileToOutput(string $entry): Output
    {
        $real = realpath($entry);
        if ($real === false) {
            throw new LessException('找不到 Less 入口文件：' . $entry);
        }

        // 第一遍：收集全部变量与混入（Less 的变量是惰性求值 + 全局可见）
        $tree = $this->load($real);
        $this->collect($tree);

        // 第二遍：展开并渲染
        $out = new Output();
        $this->render($tree, [], [], $out);

        return $out;
    }

    // -----------------------------------------------------------------
    // 读取与解析
    // -----------------------------------------------------------------

    /**
     * 读取文件并把 @import 就地展开
     *
     * @return list<array<string, mixed>>
     */
    private function load(string $file): array
    {
        $real = realpath($file);
        if ($real === false) {
            throw new LessException('找不到被导入的文件：' . $file);
        }

        if (isset($this->loaded[$real])) {
            return [];
        }
        $this->loaded[$real] = true;

        $source = file_get_contents($real);
        if ($source === false) {
            throw new LessException('无法读取文件：' . $real);
        }

        // 去掉 BOM
        $source = preg_replace('/^\xEF\xBB\xBF/', '', $source) ?? $source;

        $nodes = (new Parser($source, $real))->parse();
        $result = [];

        foreach ($nodes as $node) {
            if ($node['kind'] === 'import') {
                $imported = $this->resolveImport((string) $node['selector'], $real);
                foreach ($imported as $child) {
                    $result[] = $child;
                }
                continue;
            }
            $result[] = $node;
        }

        return $result;
    }

    /**
     * 解析 @import 目标路径
     *
     * @return list<array<string, mixed>>
     */
    private function resolveImport(string $spec, string $fromFile): array
    {
        // 去掉 (reference) / (optional) 之类的修饰
        $spec = trim((string) preg_replace('/^\([a-z]+\)\s*/i', '', trim($spec)));
        $spec = trim($spec, "\"'");

        if ($spec === '') {
            throw new LessException('@import 缺少文件名（来自 ' . $fromFile . '）');
        }

        $candidates = [];

        if (str_starts_with($spec, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $spec) === 1) {
            $candidates[] = $spec;
        } else {
            // 相对当前文件
            $candidates[] = dirname($fromFile) . '/' . $spec;
            // 搜索路径
            foreach ($this->paths as $path) {
                $candidates[] = $path . '/' . $spec;
            }
        }

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $this->load($candidate);
            }
            // 允许省略 .less 后缀
            if (!str_ends_with($candidate, '.less') && is_file($candidate . '.less')) {
                return $this->load($candidate . '.less');
            }
        }

        throw new LessException('无法解析 @import "' . $spec . '"（来自 ' . basename($fromFile) . '）');
    }

    // -----------------------------------------------------------------
    // 收集变量与混入
    // -----------------------------------------------------------------

    /**
     * @param list<array<string, mixed>> $nodes
     */
    private function collect(array $nodes): void
    {
        foreach ($nodes as $node) {
            if ($node['kind'] === 'decl') {
                $name = (string) $node['selector'];
                if (str_starts_with($name, '@') && !$this->isAtRule($name)) {
                    // 变量的值本身也可能引用别的变量，这里先原样存，渲染时再展开
                    $this->variables[$name] = trim((string) $node['value']);
                }
                continue;
            }

            if ($node['kind'] === 'rule' && $this->isMixinDefinition($node)) {
                [$name, $params] = $this->parseMixinSignature((string) $node['selector']);
                $this->mixins[$name][] = [
                    'params' => $params,
                    'body'   => $node['body'],
                    'line'   => $node['line'],
                    'file'   => $node['file'],
                ];
                continue;
            }

            if ($node['kind'] === 'atrule') {
                $this->collect($node['body']);
                continue;
            }

            if ($node['kind'] === 'keyframes') {
                // 帧内的声明不需要参与变量收集
                continue;
            }

            if ($node['kind'] === 'rule') {
                $this->collect($node['body']);
            }
        }
    }

    /**
     * 判断一个语句是不是混入定义：形如 .name(...) 且块内只有声明
     *
     * 这里的原则是宽松——真正的普通规则（如 .home-container、.sparkle.s1）
     * 名字后面不会紧跟括号，所以不会误判。
     *
     * @param array<string, mixed> $node
     */
    private function isMixinDefinition(array $node): bool
    {
        $selector = trim((string) $node['selector']);

        if (preg_match('/^\.[A-Za-z_][\w-]*\s*\(.*\)$/s', $selector) !== 1) {
            return false;
        }

        // 块内必须全是声明或嵌套（不能出现选择器列表）
        foreach ($node['body'] as $child) {
            if ($child['kind'] !== 'decl') {
                return false;
            }
        }

        return true;
    }

    /**
     * 拆出混入名与参数列表
     *
     * @return array{0: string, 1: string}
     */
    private function parseMixinSignature(string $selector): array
    {
        $pos = strpos($selector, '(');
        $name = trim(substr($selector, 0, $pos));
        $params = trim(substr($selector, $pos + 1), " \t\n\r\0\x0B)");

        return [$name, $params];
    }

    /** 是否是 CSS 顶层 at 规则（不是变量） */
    private function isAtRule(string $name): bool
    {
        return in_array($name, [
            '@media', '@supports', '@keyframes', '@font-face', '@charset',
            '@import', '@namespace', '@page', '@layer', '@property', '@container',
        ], true);
    }

    // -----------------------------------------------------------------
    // 渲染
    // -----------------------------------------------------------------

    /**
     * @param list<array<string, mixed>> $nodes
     * @param list<string>               $parents    当前父选择器
     * @param list<string>               $atWrappers 当前所处的 at 规则（@media 等）
     */
    private function render(array $nodes, array $parents, array $atWrappers, Output $out, array $scope = []): void
    {
        foreach ($nodes as $node) {
            switch ($node['kind']) {
                case 'decl':
                    // 顶层变量不产出 CSS
                    $name = (string) $node['selector'];
                    if (str_starts_with($name, '@') && !$this->isAtRule($name)) {
                        continue 2;
                    }

                    // 混入调用
                    if ($this->isMixinCall($name, $scope)) {
                        $this->expandMixin($name, $out, $scope);
                        continue 2;
                    }

                    // 处在规则内部才算声明；顶层裸露的声明直接丢弃
                    if ($parents === []) {
                        continue 2;
                    }

                    $value = $this->interpolate((string) $node['value'], $scope);
                    $out->declaration($name, $value);
                    continue 2;

                case 'rule':
                    $selector = $this->interpolate((string) $node['selector'], $scope);

                    // 混入定义不产出 CSS
                    if ($this->isMixinDefinition($node)) {
                        continue 2;
                    }

                    $selectors = $this->combineSelectors($parents, $selector);
                    $ownScope = $this->scopeOf($node['body'], $scope);

                    // 声明这条规则所属的 at 规则链，Output 负责开关 @media / @supports
                    $out->syncAtRules($atWrappers);

                    // 本层的声明与混入调用 -> 写进当前选择器块
                    $out->openRule($selectors);
                    $this->renderDeclarationsOnly($node['body'], $ownScope, $out);
                    $out->closeRule();

                    // 本层的子规则 / 嵌套 at 规则 -> 递归处理
                    // 注意：不能再对同一层调用 render()，否则声明会被重复输出
                    $this->renderChildren($node['body'], $selectors, $atWrappers, $out, $ownScope);
                    continue 2;

                case 'keyframes':
                    // @keyframes 自成一块，内部是百分比帧列表，不参与 at 规则链管理
                    $out->syncAtRules([]);
                    $out->openKeyframes($this->interpolate((string) $node['selector'], $scope));

                    foreach ($node['frames'] as $frame) {
                        $out->openFrame($this->interpolate((string) $frame['selector'], $scope));

                        foreach ($frame['body'] as $child) {
                            if ($child['kind'] !== 'decl') {
                                continue;
                            }
                            $out->declaration(
                                (string) $child['selector'],
                                $this->interpolate((string) $child['value'], $scope)
                            );
                        }

                        $out->closeFrame();
                    }

                    $out->closeKeyframes();
                    continue 2;

                case 'atrule':
                    $prelude = $this->interpolate((string) $node['selector'], $scope);
                    // 变量/混入定义要能被内部使用
                    $innerScope = $this->scopeOf($node['body'], $scope);

                    if ($this->isKeyframes($prelude)) {
                        // @keyframes 内部是百分比选择器，不需要拼接父选择器
                        $out->openAtRule($prelude);
                        $this->renderKeyframes($node['body'], $innerScope, $out);
                        $out->closeAtRule();
                        continue 2;
                    }

                    $this->render($node['body'], $parents, [...$atWrappers, $prelude], $out, $innerScope);
                    continue 2;

                default:
                    continue 2;
            }
        }
    }

    /**
     * 只渲染子规则与嵌套 at 规则（当前层的声明已由 renderDeclarationsOnly 处理）
     *
     * @param list<array<string, mixed>> $nodes
     * @param list<string>               $parents
     * @param list<string>               $atWrappers
     */
    private function renderChildren(
        array $nodes,
        array $parents,
        array $atWrappers,
        Output $out,
        array $scope
    ): void {
        $children = [];

        foreach ($nodes as $node) {
            if ($node['kind'] === 'rule') {
                if ($this->isMixinDefinition($node)) {
                    continue;
                }
                $children[] = $node;
                continue;
            }

            if ($node['kind'] === 'atrule') {
                $children[] = $node;
            }
        }

        if ($children !== []) {
            $this->render($children, $parents, $atWrappers, $out, $scope);
        }
    }

    /** @keyframes 内部特殊处理 */
    private function renderKeyframes(array $nodes, array $scope, Output $out): void
    {
        foreach ($nodes as $node) {
            if ($node['kind'] !== 'rule') {
                continue;
            }
            $out->openRule([$this->interpolate((string) $node['selector'], $scope)], []);
            foreach ($node['body'] as $child) {
                if ($child['kind'] === 'decl') {
                    $out->declaration(
                        (string) $child['selector'],
                        $this->interpolate((string) $child['value'], $scope)
                    );
                }
            }
            $out->closeRule();
        }
    }

    /**
     * 只渲染声明与混入调用（不含子规则）
     */
    private function renderDeclarationsOnly(array $nodes, array $scope, Output $out): void
    {
        foreach ($nodes as $node) {
            if ($node['kind'] !== 'decl') {
                continue;
            }

            $name = (string) $node['selector'];

            if (str_starts_with($name, '@') && !$this->isAtRule($name)) {
                continue;
            }

            if ($this->isMixinCall($name, $scope)) {
                $this->expandMixin($name, $out, $scope);
                continue;
            }

            $out->declaration($name, $this->interpolate((string) $node['value'], $scope));
        }
    }

    /**
     * 该层是否为混入调用：.name() 或 .name(args)，且存在同名混入定义
     */
    private function isMixinCall(string $statement, array $scope): bool
    {
        if (str_starts_with($statement, '@')) {
            return false;
        }

        if (preg_match('/^\.[A-Za-z_][\w-]*\s*\(.*\)$/s', trim($statement)) !== 1) {
            return false;
        }

        $name = trim(substr($statement, 0, (int) strpos($statement, '(')));

        return isset($this->mixins[$name]);
    }

    /**
     * 展开混入：解析实参 -> 绑定形参 -> 渲染混入体内的声明
     */
    private function expandMixin(string $statement, Output $out, array $scope): void
    {
        $pos = strpos($statement, '(');
        $name = trim(substr($statement, 0, $pos));
        $argumentText = trim(substr($statement, $pos + 1), " \t\n\r\0\x0B)");

        $args = $this->splitTopLevel($argumentText);

        foreach ($this->mixins[$name] ?? [] as $definition) {
            $bindings = $this->bindArguments((string) $definition['params'], $args);
            $inner = array_merge($scope, $bindings);

            foreach ($definition['body'] as $child) {
                if ($child['kind'] !== 'decl') {
                    continue;
                }

                $childName = (string) $child['selector'];

                if (str_starts_with($childName, '@') && !$this->isAtRule($childName)) {
                    continue;
                }

                if ($this->isMixinCall($childName, $inner)) {
                    $this->expandMixin($childName, $out, $inner);
                    continue;
                }

                $out->declaration($childName, $this->interpolate((string) $child['value'], $inner));
            }

            return; // 只展开第一个匹配的定义（与 Less 的重载优先级一致）
        }
    }

    /**
     * 形参 -> 实参绑定，支持默认值与命名参数
     *
     * @param list<string> $args
     * @return array<string, string>
     */
    private function bindArguments(string $params, array $args): array
    {
        $bindings = [];

        if (trim($params) === '') {
            return $bindings;
        }

        $definitions = $this->splitTopLevel($params);
        $positional = [];

        // 先分离命名参数（name: value）
        foreach ($args as $arg) {
            $colon = $this->findTopLevelColon($arg);
            if ($colon !== null) {
                $key = trim(substr($arg, 0, $colon));
                $bindings[$key] = trim(substr($arg, $colon + 1));
            } else {
                $positional[] = trim($arg);
            }
        }

        $index = 0;
        foreach ($definitions as $definition) {
            $definition = trim($definition);
            if ($definition === '') {
                continue;
            }

            $colon = $this->findTopLevelColon($definition);
            if ($colon !== null) {
                $key = trim(substr($definition, 0, $colon));
                $default = trim(substr($definition, $colon + 1));
            } else {
                $key = $definition;
                $default = null;
            }

            if (array_key_exists($key, $bindings)) {
                continue;
            }

            if (isset($positional[$index])) {
                $bindings[$key] = $positional[$index];
                $index++;
                continue;
            }

            if ($default !== null) {
                $bindings[$key] = $default;
            }
        }

        return $bindings;
    }

    /** 找到处于顶层的冒号（跳过括号里的内容） */
    private function findTopLevelColon(string $text): ?int
    {
        $depth = 0;
        $length = strlen($text);

        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            } elseif ($char === ':' && $depth === 0) {
                return $i;
            }
        }

        return null;
    }

    // -----------------------------------------------------------------
    // 表达式处理
    // -----------------------------------------------------------------

    /**
     * 收集该层的变量绑定（含 mixin 调用产生的局部变量）
     *
     * @param list<array<string, mixed>> $nodes
     * @return array<string, string>
     */
    private function scopeOf(array $nodes, array $parentScope): array
    {
        $scope = $parentScope;

        foreach ($nodes as $node) {
            if ($node['kind'] !== 'decl') {
                continue;
            }
            $name = (string) $node['selector'];
            if (str_starts_with($name, '@') && !$this->isAtRule($name)) {
                $scope[$name] = trim((string) $node['value']);
            }
        }

        return $scope;
    }

    /**
     * 变量替换 + 简单算式求值
     */
    private function interpolate(string $value, array $scope): string
    {
        $value = $this->substituteVariables($value, $scope, 0);
        return $this->evaluateSimpleMath($value);
    }

    private function substituteVariables(string $value, array $scope, int $depth): string
    {
        if ($depth > 12 || !str_contains($value, '@')) {
            return $value;
        }

        $changed = false;

        $result = preg_replace_callback(
            '/@([A-Za-z_][\w-]*)/',
            function (array $m) use ($scope, &$changed): string {
                $name = '@' . $m[1];

                if (array_key_exists($name, $scope)) {
                    $changed = true;
                    return (string) $scope[$name];
                }

                if (array_key_exists($name, $this->variables)) {
                    $changed = true;
                    return (string) $this->variables[$name];
                }

                return $m[0];
            },
            $value
        );

        $value = $result ?? $value;

        // 变量里还可能嵌套引用别的变量，递归展开
        return $changed ? $this->substituteVariables($value, $scope, $depth + 1) : $value;
    }

    /**
     * 整段值都是算式时才求值，例如 "480px * 3"、"2 * 12px"
     *
     * 只要包含空格分隔的多段（如 "0 0 0 4px"）或函数调用就不处理。
     */
    private function evaluateSimpleMath(string $value): string
    {
        $trimmed = trim($value);

        if (preg_match('#^(-?\d*\.?\d+)(px|rem|em|%|s|ms|vh|vw)?\s*([*/+-])\s*(-?\d*\.?\d+)(px|rem|em|%|s|ms|vh|vw)?$#', $trimmed, $m) !== 1) {
            return $value;
        }

        // 减法/加法的歧义太多（例如 0 -1px），只处理乘除
        if ($m[3] !== '*' && $m[3] !== '/') {
            return $value;
        }

        $left = (float) $m[1];
        $right = (float) $m[4];
        $unit = $m[2] !== '' ? $m[2] : $m[5];

        if ($m[3] === '/' && $right == 0.0) {
            return $value;
        }

        $result = $m[3] === '*' ? $left * $right : $left / $right;

        // 输出时去掉多余小数位
        $formatted = rtrim(rtrim(number_format($result, 4, '.', ''), '0'), '.');

        return $formatted . $unit;
    }

    // -----------------------------------------------------------------
    // 选择器拼接
    // -----------------------------------------------------------------

    /**
     * 把子选择器与父选择器拼接
     *
     * @param list<string> $parents
     * @return list<string>
     */
    private function combineSelectors(array $parents, string $child): array
    {
        $children = $this->splitTopLevel($child);

        if ($children === []) {
            $children = [trim($child)];
        }

        if ($parents === []) {
            return array_map('trim', $children);
        }

        $result = [];

        foreach ($parents as $parent) {
            foreach ($children as $item) {
                $item = trim($item);

                if (str_contains($item, '&')) {
                    $result[] = trim(str_replace('&', $parent, $item));
                } else {
                    $result[] = trim($parent . ' ' . $item);
                }
            }
        }

        return $result;
    }

    private function isKeyframes(string $prelude): bool
    {
        return preg_match('/^@(-\w+-)?keyframes\b/i', trim($prelude)) === 1;
    }

    /**
     * 按顶层逗号拆分（跳过括号与引号内的逗号）
     *
     * @return list<string>
     */
    private function splitTopLevel(string $text): array
    {
        if (trim($text) === '') {
            return [];
        }

        $parts = [];
        $buffer = '';
        $depth = 0;
        $quote = null;
        $length = strlen($text);

        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];

            if ($quote !== null) {
                $buffer .= $char;
                if ($char === $quote && ($i === 0 || $text[$i - 1] !== '\\')) {
                    $quote = null;
                }
                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
                $buffer .= $char;
                continue;
            }

            if ($char === '(' || $char === '[') {
                $depth++;
            } elseif ($char === ')' || $char === ']') {
                $depth--;
            }

            if ($char === ',' && $depth === 0) {
                $parts[] = trim($buffer);
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        if (trim($buffer) !== '') {
            $parts[] = trim($buffer);
        }

        return $parts;
    }
}
