<?php
declare(strict_types=1);

/**
 * 对比 PHP 编译器输出与 Node/less 输出是否语义等价（一次性校验脚本）
 *
 * 思路：把 CSS 逐字符扫描成原子块
 *   - 选择器块：以 { 开始，累积声明直到匹配的 }
 *   - at 规则块：以 @media/@supports/@keyframes 开始，内部可以再嵌选择器块
 * 然后归一化成 [ "上下文 || 选择器" => [ "prop:value" ] ] 再做集合比较。
 */

require __DIR__ . '/public/bootstrap.php';

use Lolilota\Less\Compiler;

$phpCss = (new Compiler([LOLILOTA_ROOT . '/src/less']))->compileFile(LOLILOTA_ROOT . '/src/less/style.less');
$nodeCss = (string) file_get_contents(LOLILOTA_ROOT . '/dist/css/lolilota.css');

/**
 * @return array<string, array<string, true>>
 */
function parseCss(string $css): array
{
    $css = preg_replace('#/\*.*?\*/#s', '', $css) ?? $css;

    $rules = [];
    /** @var list<array{selector:string, decls:list<string>, ats:list<string>}> $stack */
    $stack = [];
    $pending = '';       // 正在累积的选择器文本（当前层）
    $quote = null;
    $len = strlen($css);

    for ($i = 0; $i < $len; $i++) {
        $ch = $css[$i];

        if ($quote !== null) {
            if ($stack !== []) {
                $stack[count($stack) - 1]['decls'][] = $ch;
            } else {
                $pending .= $ch;
            }
            if ($ch === $quote && ($i === 0 || $css[$i - 1] !== '\\')) {
                $quote = null;
            }
            continue;
        }

        if ($ch === '"' || $ch === "'") {
            $quote = $ch;
            if ($stack !== []) {
                $stack[count($stack) - 1]['decls'][] = $ch;
            } else {
                $pending .= $ch;
            }
            continue;
        }

        if ($ch === '{') {
            $stack[] = [
                'selector' => trim($pending),
                'decls'    => [],
                'ats'      => [],
            ];
            $pending = '';
            continue;
        }

        if ($ch === '}') {
            $frame = array_pop($stack);
            if ($frame === null) {
                continue;
            }

            $selector = $frame['selector'];
            $body = implode('', $frame['decls']);

            if ($selector === '') {
                continue;
            }

            if (str_starts_with($selector, '@')) {
                // @keyframes 内部是百分比选择器，单独收录
                if (preg_match('/@(-\w+-)?keyframes\b/i', $selector) === 1) {
                    foreach (extractKeyframeRules($body) as $percent => $decls) {
                        foreach (splitSelectors($percent) as $one) {
                            $rules['@keyframes ' . normalizeSelector($selector) . ' || ' . $one] = $decls;
                        }
                    }
                }
                continue;
            }

            $ats = [];
            foreach ($stack as $open) {
                if (str_starts_with($open['selector'], '@')) {
                    $ats[] = normalizeSelector($open['selector']);
                }
            }
            $context = implode(' && ', $ats);

            $decls = normalizeDeclarations($body);
            if ($decls === []) {
                continue;
            }

            foreach (splitSelectors($selector) as $one) {
                $key = ($context !== '' ? $context . ' || ' : '') . $one;
                $rules[$key] = $decls;
            }
            continue;
        }

        if ($stack !== []) {
            $stack[count($stack) - 1]['decls'][] = $ch;
        } else {
            $pending .= $ch;
        }
    }

    return $rules;
}

/** @return array<string, array<string, true>> */
function extractKeyframeRules(string $body): array
{
    $out = [];
    $pending = '';
    $decls = [];
    $count = 0;
    $len = strlen($body);

    for ($i = 0; $i < $len; $i++) {
        $ch = $body[$i];
        if ($ch === '{') {
            $count++;
            continue;
        }
        if ($ch === '}') {
            $count--;
            if ($count === 0) {
                $out[trim($pending)] = normalizeDeclarations(implode('', $decls));
                $pending = '';
                $decls = [];
            }
            continue;
        }
        if ($count > 0) {
            $decls[] = $ch;
        } else {
            $pending .= $ch;
        }
    }

    return $out;
}

/** @return list<string> */
function splitSelectors(string $selector): array
{
    $parts = [];
    $buf = '';
    $depth = 0;
    $len = strlen($selector);

    for ($i = 0; $i < $len; $i++) {
        $ch = $selector[$i];
        if ($ch === '(') $depth++;
        if ($ch === ')') $depth--;
        if ($ch === ',' && $depth === 0) {
            if (trim($buf) !== '') $parts[] = normalizeSelector($buf);
            $buf = '';
            continue;
        }
        $buf .= $ch;
    }
    if (trim($buf) !== '') $parts[] = normalizeSelector($buf);

    return $parts;
}

function normalizeSelector(string $selector): string
{
    $selector = preg_replace('/\s+/', ' ', trim($selector)) ?? $selector;
    return preg_replace('/\s*([>+~])\s*/', '$1', $selector) ?? $selector;
}

/** @return array<string, true> */
function normalizeDeclarations(string $body): array
{
    $out = [];
    foreach (explode(';', $body) as $decl) {
        $decl = trim($decl);
        if ($decl === '' || !str_contains($decl, ':')) {
            continue;
        }
        $colon = strpos($decl, ':');
        $prop = strtolower(trim(substr($decl, 0, $colon)));
        $value = preg_replace('/\s+/', ' ', trim(substr($decl, $colon + 1))) ?? '';
        $value = preg_replace('/\b0+\.(\d)/', '0.$1', $value) ?? $value;
        $value = preg_replace_callback('/#[0-9a-fA-F]{3,8}\b/', static fn ($m) => strtolower($m[0]), $value) ?? $value;
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
        $differ[$key] = ['missing' => array_keys($missing), 'extra' => array_keys($extra)];
    }
}

echo "PHP 规则数：" . count($a) . "    Node 规则数：" . count($b) . "\n";
echo "仅 PHP 有：" . count($onlyPhp) . "    仅 Node 有：" . count($onlyNode) . "    声明不一致：" . count($differ) . "\n";

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
        if ($info['missing']) echo "      PHP 缺少: " . implode(' | ', array_slice($info['missing'], 0, 4)) . "\n";
        if ($info['extra'])   echo "      PHP 多出: " . implode(' | ', array_slice($info['extra'], 0, 4)) . "\n";
        if (++$i >= 10) break;
    }
}

exit(($onlyPhp === [] && $onlyNode === [] && $differ === []) ? 0 : 1);
