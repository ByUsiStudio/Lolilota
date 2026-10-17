<?php
declare(strict_types=1);

/**
 * 对比 PHP 编译器输出与 Node/less 输出是否语义等价（一次性校验脚本）
 *
 * 用一个严格的 CSS 遍历器把两边都解析成
 *   [ "上下文 || 选择器" => [ "prop:value", ... ] ]
 * 再逐条比对，因此格式差异（缩进、简写、大小写）不影响结论。
 */

require __DIR__ . '/public/bootstrap.php';

use Lolilota\Less\Compiler;

$phpCss = (new Compiler([LOLILOTA_ROOT . '/src/less']))->compileFile(LOLILOTA_ROOT . '/src/less/style.less');
$nodeCss = (string) file_get_contents(LOLILOTA_ROOT . '/dist/css/lolilota.css');

/**
 * 遍历 CSS，返回 [ 完整选择器 => 声明集合 ]
 *
 * @return array<string, array<string, true>>
 */
function parseCss(string $css): array
{
    $css = preg_replace('#/\*.*?\*/#s', '', $css) ?? $css;

    $rules = [];
    /** @var list<string> $atStack 外层 at 规则 */
    $atStack = [];
    /** @var list<string> $selStack 选择器栈 */
    $selStack = [];
    /** @var list<string> $declStack 每层的声明缓冲 */
    $declStack = [];
    /** @var list<string> $textStack 每层正在读的文本 */
    $textStack = [''];

    $length = strlen($css);
    $quote = null;

    for ($i = 0; $i < $length; $i++) {
        $char = $css[$i];

        if ($quote !== null) {
            $textStack[count($textStack) - 1] .= $char;
            if ($char === $quote && $css[$i - 1] !== '\\') {
                $quote = null;
            }
            continue;
        }

        if ($char === '"' || $char === "'") {
            $quote = $char;
            $textStack[count($textStack) - 1] .= $char;
            continue;
        }

        if ($char === '{') {
            $prelude = trim(array_pop($textStack));
            $textStack[] = '';
            $selStack[] = $prelude;
            $declStack[] = '';
            continue;
        }

        if ($char === '}') {
            $declarations = array_pop($declStack) ?? '';
            $selector = array_pop($selStack) ?? '';
            array_pop($textStack);
            if ($textStack === []) {
                $textStack[] = '';
            }
            $textStack[count($textStack) - 1] .= ' ';

            if ($selector !== '' && !str_starts_with($selector, '@')) {
                $context = '';
                foreach ($selStack as $parent) {
                    if (str_starts_with($parent, '@')) {
                        $context = normalizePrelude($parent);
                    }
                }

                $normalized = normalizeDeclarations($declarations);
                if ($normalized !== []) {
                    foreach (splitSelectors($selector) as $one) {
                        $key = ($context !== '' ? $context . ' || ' : '') . $one;
                        $rules[$key] = $normalized;
                    }
                }
            }
            continue;
        }

        $textStack[count($textStack) - 1] .= $char;
    }

    return $rules;
}

function normalizePrelude(string $prelude): string
{
    return preg_replace('/\s+/', ' ', trim($prelude)) ?? $prelude;
}

/** @return list<string> */
function splitSelectors(string $selector): array
{
    $parts = [];
    $buffer = '';
    $depth = 0;
    $length = strlen($selector);

    for ($i = 0; $i < $length; $i++) {
        $char = $selector[$i];
        if ($char === '(') $depth++;
        if ($char === ')') $depth--;
        if ($char === ',' && $depth === 0) {
            $parts[] = normalizeSelector($buffer);
            $buffer = '';
            continue;
        }
        $buffer .= $char;
    }
    if (trim($buffer) !== '') {
        $parts[] = normalizeSelector($buffer);
    }

    return $parts;
}

function normalizeSelector(string $selector): string
{
    $selector = preg_replace('/\s+/', ' ', trim($selector)) ?? $selector;
    return preg_replace('/\s*([>+~])\s*/', '$1', $selector) ?? $selector;
}

/** @return array<string, true> */
function normalizeDeclarations(string $declarations): array
{
    $out = [];
    foreach (explode(';', $declarations) as $declaration) {
        $declaration = trim($declaration);
        if ($declaration === '' || !str_contains($declaration, ':')) {
            continue;
        }
        $colon = strpos($declaration, ':');
        $prop = strtolower(trim(substr($declaration, 0, $colon)));
        $value = preg_replace('/\s+/', ' ', trim(substr($declaration, $colon + 1))) ?? '';

        // 归一化：补前导 0、统一小写十六进制
        $value = preg_replace('/\b0+\.(\d)/', '0.$1', $value) ?? $value;
        $value = preg_replace_callback('/#[0-9a-fA-F]{3,8}\b/', fn ($m) => strtolower($m[0]), $value) ?? $value;

        $out[$prop . ':' . $value] = true;
    }
    return $out;
}

$a = parseCss($phpCss);
$b = parseCss($nodeCss);

$onlyPhp = array_diff_key($a, $b);
$onlyNode = array_diff_key($b, $a);
$differ = [];

foreach (array_intersect_key($a, $b) as $key => $decls) {
    $missing = array_diff_key($b[$key], $decls);
    $extra = array_diff_key($decls, $b[$key]);
    if ($missing !== [] || $extra !== []) {
        $differ[$key] = ['PHP 缺少' => array_keys($missing), 'PHP 多出' => array_keys($extra)];
    }
}

echo "PHP 编译器规则数：" . count($a) . "\n";
echo "Node/less 规则数：" . count($b) . "\n";
echo "仅 PHP 有：" . count($onlyPhp) . "\n";
echo "仅 Node 有：" . count($onlyNode) . "\n";
echo "声明不一致：" . count($differ) . "\n";

if ($onlyPhp !== []) {
    echo "\n--- 仅 PHP 有的选择器（前 12）---\n";
    foreach (array_slice(array_keys($onlyPhp), 0, 12) as $k) echo "  " . $k . "\n";
}
if ($onlyNode !== []) {
    echo "\n--- 仅 Node 有的选择器（前 12）---\n";
    foreach (array_slice(array_keys($onlyNode), 0, 12) as $k) echo "  " . $k . "\n";
}
if ($differ !== []) {
    echo "\n--- 声明不一致（前 10）---\n";
    $i = 0;
    foreach ($differ as $k => $info) {
        echo "  " . $k . "\n";
        if ($info['PHP 缺少']) echo "      PHP 缺少: " . implode(' | ', array_slice($info['PHP 缺少'], 0, 5)) . "\n";
        if ($info['PHP 多出']) echo "      PHP 多出: " . implode(' | ', array_slice($info['PHP 多出'], 0, 5)) . "\n";
        if (++$i >= 10) break;
    }
}

exit(($onlyPhp === [] && $onlyNode === [] && $differ === []) ? 0 : 1);
