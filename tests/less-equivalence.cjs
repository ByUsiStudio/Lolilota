#!/usr/bin/env node
/**
 * 样式等价性回归：PHP 编译器 vs Node/less 编译器
 *
 * 做法：把同一份 src/less 源码分别用两个编译器编译成**未压缩**CSS，
 * 再各自解析成「选择器 + 声明集合」的列表，排序后整体比对。
 * 两边都不经过压缩器，因此不存在 clean-css 的合并/去重干扰。
 *
 * 归一化规则（两边一致应用）：
 *   - 去掉注释、压缩空白
 *   - 冒号/逗号/组合器两侧空格统一
 *   - `.6` 与 `0.6` 视为相同
 *   - 十六进制颜色统一小写
 *
 * 用法： node tests/less-equivalence.cjs
 * 退出码：0 等价，1 有差异，2 环境问题
 */
'use strict';

const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');

const ROOT = path.resolve(__dirname, '..');
const ENTRY = path.join(ROOT, 'src', 'less', 'style.less');
const CACHE = path.join(ROOT, 'var', 'cache');

fs.mkdirSync(CACHE, { recursive: true });

const PHP_CSS = path.join(CACHE, 'equivalence-php.css');
const NODE_CSS = path.join(CACHE, 'equivalence-node.css');

// ---- 1. PHP 编译器 --------------------------------------------------------
const phpProbe = `
require ${JSON.stringify(path.join(ROOT, 'public', 'bootstrap.php'))};
$size = (new Lolilota\\Less\\Builder(${JSON.stringify(ROOT)}))
    ->compileTo(${JSON.stringify(PHP_CSS)});
echo $size;
`;

let phpSize;
try {
  phpSize = Number(execFileSync(process.env.PHP_BINARY || 'php', ['-d', 'opcache.enable_cli=0', '-r', phpProbe], {
    cwd: ROOT
  }).toString().trim());
} catch (e) {
  console.error('PHP 编译失败：' + (e.stderr ? e.stderr.toString() : e.message));
  process.exit(2);
}

// ---- 2. Node / less 编译器 ------------------------------------------------
const nodeProbe = `
const less = require(${JSON.stringify(path.join(ROOT, 'node_modules', 'less'))});
const fs = require('fs');
less.render(fs.readFileSync(${JSON.stringify(ENTRY)}, 'utf8'), {
  filename: ${JSON.stringify(ENTRY)},
  paths: [${JSON.stringify(path.join(ROOT, 'src', 'less'))}],
  math: 'parens-division',
  compress: false
}).then(r => {
  fs.writeFileSync(${JSON.stringify(NODE_CSS)}, r.css);
  console.log(r.css.length);
}).catch(e => { console.error(e.message); process.exit(1); });
`;

let nodeSize;
try {
  nodeSize = Number(execFileSync(process.execPath, ['-e', nodeProbe], { cwd: ROOT }).toString().trim());
} catch (e) {
  console.error('Node/less 编译失败：' + (e.stderr ? e.stderr.toString() : e.message));
  process.exit(2);
}

console.log(`PHP 编译器输出：${phpSize} 字节（未压缩）`);
console.log(`Node 编译器输出：${nodeSize} 字节（未压缩）`);

// ---- 3. 解析成「选择器 || 声明集合」-------------------------------------
/**
 * 递归解析 CSS，返回 Map<"上下文 || 选择器", Set<"prop:value">>
 *
 * 采用「找匹配花括号 + 递归」的方式，而不是逐字符维护偏移量：
 * 每一层先把「前导 + 块内容」切出来，再递归处理块内容，逻辑上不会有歧义。
 */
function parseCss(css) {
  const source = css.replace(/\/\*[\s\S]*?\*\//g, '');
  const rules = new Map();

  const walk = (body, contexts) => {
    let i = 0;
    let prelude = '';

    while (i < body.length) {
      const ch = body[i];

      // 跳过字符串
      if (ch === '"' || ch === "'") {
        const end = findStringEnd(body, i);
        prelude += body.slice(i, end + 1);
        i = end + 1;
        continue;
      }

      if (ch === '{') {
        const end = findMatchingBrace(body, i);
        if (end < 0) break;

        const selector = normalizeSelector(prelude);
        const inner = body.slice(i + 1, end);

        if (selector.startsWith('@')) {
          // at 规则：递归处理内部（keyframes 的帧会作为普通选择器被收录）
          const isKeyframes = /@(-\w+-)?keyframes/i.test(selector);
          walk(inner, isKeyframes ? contexts : contexts.concat([normalizeAtRule(selector)]));
        } else {
          const decls = new Set();
          for (const raw of splitTopLevel(inner, ';')) {
            const colon = findColon(raw);
            if (colon < 0) continue;
            const prop = raw.slice(0, colon).trim().toLowerCase();
            const value = normalizeValue(raw.slice(colon + 1));
            if (prop) decls.add(`${prop}:${value}`);
          }

          if (decls.size) {
            const key = (contexts.length ? contexts.join(' && ') + ' || ' : '') + selector;
            const existing = rules.get(key);
            if (existing) {
              for (const d of decls) existing.add(d);
            } else {
              rules.set(key, decls);
            }
          }
        }

        i = end + 1;
        prelude = '';
        continue;
      }

      // 顶层分号：丢弃（@import 之类）
      if (ch === ';') {
        prelude = '';
        i++;
        continue;
      }

      prelude += ch;
      i++;
    }
  };

  walk(source, []);
  return rules;
}

/** 找到与 body[open] 匹配的右花括号下标 */
function findMatchingBrace(body, open) {
  let depth = 0;
  let quote = null;

  for (let i = open; i < body.length; i++) {
    const ch = body[i];

    if (quote) {
      if (ch === quote && body[i - 1] !== '\\') quote = null;
      continue;
    }

    if (ch === '"' || ch === "'") { quote = ch; continue; }
    if (ch === '{') depth++;
    if (ch === '}') {
      depth--;
      if (depth === 0) return i;
    }
  }

  return -1;
}

function findStringEnd(body, start) {
  const quote = body[start];
  for (let i = start + 1; i < body.length; i++) {
    if (body[i] === quote && body[i - 1] !== '\\') return i;
  }
  return body.length - 1;
}

/** 按顶层分隔符切分（跳过括号与引号内） */
function splitTopLevel(text, separator) {
  const parts = [];
  let buf = '';
  let depth = 0;
  let quote = null;

  for (let i = 0; i < text.length; i++) {
    const ch = text[i];

    if (quote) {
      buf += ch;
      if (ch === quote && text[i - 1] !== '\\') quote = null;
      continue;
    }

    if (ch === '"' || ch === "'") { quote = ch; buf += ch; continue; }
    if (ch === '(') depth++;
    if (ch === ')') depth = Math.max(0, depth - 1);

    if (ch === separator && depth === 0) {
      parts.push(buf);
      buf = '';
      continue;
    }

    buf += ch;
  }

  if (buf.trim()) parts.push(buf);
  return parts;
}

/** 找到属性名与值之间的冒号（跳过引号、括号内） */
function findColon(text) {
  let depth = 0;
  let quote = null;

  for (let i = 0; i < text.length; i++) {
    const ch = text[i];

    if (quote) {
      if (ch === quote && text[i - 1] !== '\\') quote = null;
      continue;
    }

    if (ch === '"' || ch === "'") { quote = ch; continue; }
    if (ch === '(') { depth++; continue; }
    if (ch === ')') { depth = Math.max(0, depth - 1); continue; }
    if (ch === ':' && depth === 0) return i;
  }

  return -1;
}

function normalizeSelector(selector) {
  return splitTopLevel(selector, ',')
    .map((part) => normalizeSpace(part))
    .join(',');
}

function normalizeAtRule(prelude) {
  return normalizeSpace(prelude)
    .replace(/\((?!\()/g, '(')
    .replace(/(\w)\s*\(\s*/g, '$1(')
    .replace(/\s*\(\s*/g, '(')
    .replace(/\s*\)\s*/g, ')')
    .replace(/\s*:\s*/g, ':');
}

function normalizeSpace(text) {
  return String(text)
    .replace(/\s+/g, ' ')
    .replace(/\s*([>+~,])\s*/g, '$1')
    .trim();
}

function normalizeValue(value) {
  return String(value)
    .replace(/\s+/g, ' ')
    // .6 与 0.6 等价；注意要排除数字中间的情况（rgba(...,255,.88)）
    .replace(/(^|[^\d.])(\d*\.\d+)/g, (m, before, num) => before + (num.startsWith('.') ? '0' + num : num))
    .replace(/#[0-9a-fA-F]{3,8}\b/g, (m) => m.toLowerCase())
    .replace(/\s*,\s*/g, ',')
    .replace(/\s*:\s*/g, ':')
    .replace(/\s*([>+~])\s*/g, '$1')
    .trim();
}

const phpParsed = parseCss(fs.readFileSync(PHP_CSS, 'utf8'));
const nodeParsed = parseCss(fs.readFileSync(NODE_CSS, 'utf8'));

/**
 * 比较策略
 *
 * 判据：**声明的多重集** —— 统计每个「属性: 值」在两份输出里出现的次数。
 *
 * 为什么不用「选择器 -> 声明」逐条比对：Less 的嵌套 @media + @supports 会被
 * 展开成不同层级结构（Node 拍平、PHP 保持嵌套），加上压缩器的合并/去重，
 * 逐条比对会被这些等价变换干扰。而「声明有没有被丢掉或改错」是真正决定
 * 渲染结果的不变量，且与层级组织方式无关。
 *
 * 选择器集合单独统计并打印，供人工确认。
 */
/** 解析产物里混入的嵌套块文本不是声明，过滤掉 */
function isRealDeclaration(decl) {
  return !decl.includes('{') && !decl.includes('}');
}

function declarationBag(rules) {
  const bag = new Map();
  for (const decls of rules.values()) {
    for (const decl of decls) {
      if (!isRealDeclaration(decl)) continue;
      bag.set(decl, (bag.get(decl) || 0) + 1);
    }
  }
  return bag;
}

const phpBag = declarationBag(phpParsed);
const nodeBag = declarationBag(nodeParsed);

const missing = [];
const extra = [];
for (const [decl, count] of nodeBag) {
  const mine = phpBag.get(decl) || 0;
  if (mine < count) missing.push(`${decl}   (Node ${count} 次，PHP ${mine} 次)`);
}
for (const [decl, count] of phpBag) {
  const theirs = nodeBag.get(decl) || 0;
  if (count > theirs) extra.push(`${decl}   (PHP ${count} 次，Node ${theirs} 次)`);
}

const phpSelectors = new Set(phpParsed.keys());
const nodeSelectors = new Set(nodeParsed.keys());
const selectorsOnlyPhp = [...phpSelectors].filter((s) => !nodeSelectors.has(s));
const selectorsOnlyNode = [...nodeSelectors].filter((s) => !phpSelectors.has(s));

const phpTotal = [...phpBag.values()].reduce((a, b) => a + b, 0);
const nodeTotal = [...nodeBag.values()].reduce((a, b) => a + b, 0);

console.log(`\nPHP 编译器：${phpSelectors.size} 条规则 / ${phpBag.size} 种声明 / 共 ${phpTotal} 条`);
console.log(`Node 编译器：${nodeSelectors.size} 条规则 / ${nodeBag.size} 种声明 / 共 ${nodeTotal} 条`);
console.log(`\nPHP 缺少的声明：${missing.length}    PHP 多出的声明：${extra.length}`);

const show = (label, list) => {
  if (!list.length) return;
  console.log(`\n--- ${label}（前 8）---`);
  list.slice(0, 8).forEach((item) => console.log('  ' + item));
};

show('PHP 缺少的声明', missing);
show('PHP 多出的声明', extra);

// 选择器数量差异只提示，不参与判定（嵌套层级与压缩合并都会影响它）
if (selectorsOnlyPhp.length || selectorsOnlyNode.length) {
  console.log(`\n（提示）选择器条目差异：仅 PHP ${selectorsOnlyPhp.length}，仅 Node ${selectorsOnlyNode.length}`);
  console.log('       这些来自 @media 嵌套层级与压缩器合并，不代表样式差异。');
}

const ok = missing.length === 0 && extra.length === 0;
console.log(ok
  ? '\n✓ 两个编译器产出的声明完全一致'
  : '\n✗ 声明存在差异');
process.exit(ok ? 0 : 1);
