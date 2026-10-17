#!/usr/bin/env node
/**
 * Lolilota 构建脚本
 *
 * 为什么不用 gulp-cli / gulp.src：
 *   1. gulp-cli 底层 v8flags 会 spawn 子进程，受限环境（AppContainer）直接 EPERM；
 *   2. gulp.src 的 glob 会把下划线开头的局部文件也当入口编译；
 *   3. 删除旧产物在 Windows 上可能因权限被拒。
 * 这里用 Less 官方 API 直接渲染单一入口，产物路径完全确定，任何环境都能跑通。
 *
 * 产物：
 *   css/lolilota.css          站点与扩展共用
 *   dist/css/lolilota.css     扩展打包目录
 *   dist/                     完整扩展目录（页面、脚本、图标、manifest.json）
 *   public/                   PHP 站点根目录（同步 dist 的前端资源）
 *
 * 用法：
 *   node build.cjs            生产构建（压缩 CSS）
 *   node build.cjs --dev      开发构建（保留可读格式）
 *   node build.cjs --watch    监听 src/less 变化并增量重建
 *   node build.cjs --dist     完整打包 dist/（页面、图标、脚本、清单）
 */
'use strict';

const fs = require('node:fs');
const path = require('node:path');
const zlib = require('node:zlib');

const less = require('less');

const ROOT = __dirname;
const SRC = path.join(ROOT, 'src');
const LESS_DIR = path.join(SRC, 'less');
const ENTRY = path.join(LESS_DIR, 'style.less');

const DIST = path.join(ROOT, 'dist');
const PUBLIC = path.join(ROOT, 'public');

// 产物文件名用 lolilota.css 而不是通用的 style.css：
// 仓库里可能残留旧版手写 style.css，同名会互相覆盖、互相阻塞。
const OUT_CSS = path.join(ROOT, 'css', 'lolilota.css');
const OUT_DIST_CSS = path.join(DIST, 'css', 'lolilota.css');

const pkg = require(path.join(ROOT, 'package.json'));

const args = process.argv.slice(2);
const isDev = args.includes('--dev');
const isWatch = args.includes('--watch');
const withDist = args.includes('--dist') || !isDev;

// ---------------------------------------------------------------------------
// 工具
// ---------------------------------------------------------------------------

function kb(bytes) {
  return (bytes / 1024).toFixed(1) + ' KB';
}

function ensureDir(dir) {
  fs.mkdirSync(dir, { recursive: true });
}

/**
 * 写文件；遇到 Windows 权限拒绝时不抛错，交给调用方决定
 * @returns {boolean} 是否写入成功
 */
function writeFileSafe(file, content) {
  ensureDir(path.dirname(file));
  try {
    fs.writeFileSync(file, content);
    return true;
  } catch (err) {
    if (err && (err.code === 'EPERM' || err.code === 'EACCES')) {
      return false;
    }
    throw err;
  }
}

function copyFileSafe(from, to) {
  ensureDir(path.dirname(to));
  try {
    fs.copyFileSync(from, to);
    return true;
  } catch (err) {
    if (err && (err.code === 'EPERM' || err.code === 'EACCES')) {
      return false;
    }
    throw err;
  }
}

function walk(dir, filter, out = []) {
  if (!fs.existsSync(dir)) return out;
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      walk(full, filter, out);
    } else if (!filter || filter(full)) {
      out.push(full);
    }
  }
  return out;
}

/**
 * 同步目录：让 targetDir 与 sourceDir 保持一致
 *
 * 先复制全部内容，再删除 targetDir 里 sourceDir 没有的多余文件与空目录。
 * 这样一旦产物改名（例如 style.css -> lolilota.css），旧文件不会残留成孤儿。
 *
 * @param {(rel: string) => boolean} [keepExtra] 返回 true 表示该文件属于手写内容，
 *        即便 sourceDir 里没有也必须保留（例如 public/ 下的 PHP 源码）。
 */
function syncDir(sourceDir, targetDir, keepExtra) {
  const copied = [];

  for (const file of walk(sourceDir)) {
    const rel = path.relative(sourceDir, file);
    if (copyFileSafe(file, path.join(targetDir, rel))) {
      copied.push(rel.split(path.sep).join('/'));
    }
  }

  const expected = new Set(copied);

  // 删除多余文件
  for (const file of walk(targetDir)) {
    const rel = path.relative(targetDir, file).split(path.sep).join('/');
    if (expected.has(rel)) continue;
    if (keepExtra && keepExtra(rel)) continue;

    try {
      fs.unlinkSync(file);
    } catch (e) {
      /* 删不掉就留着，不影响功能 */
    }
  }

  // 自底向上清理空目录
  const pruneEmpty = (dir) => {
    if (!fs.existsSync(dir)) return;
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
      if (entry.isDirectory()) pruneEmpty(path.join(dir, entry.name));
    }
    if (dir !== targetDir && fs.readdirSync(dir).length === 0) {
      try {
        fs.rmdirSync(dir);
      } catch (e) {
        /* ignore */
      }
    }
  };
  pruneEmpty(targetDir);

  return copied.length;
}

// ---- 极简 CSS 前缀补齐 -----------------------------------------------------
// 只补项目里真实用到的 flex / 用户选择 / 外观等属性，避免引入完整 autoprefixer。

const PREFIX_RULES = [
  [/^(\s*)user-select\s*:/, (indent, decl) => `${indent}-webkit-${decl}\n${indent}${decl}`],
  [/^(\s*)appearance\s*:/, (indent, decl) => `${indent}-webkit-${decl}\n${indent}${decl}`],
  [/^(\s*)backdrop-filter\s*:/, (indent, decl) => `${indent}-webkit-${decl}\n${indent}${decl}`]
];

function autoprefix(css) {
  // 逐行处理，只在属性行插入前缀，不动选择器与 @ 规则
  const lines = css.split('\n');
  const out = [];

  for (const line of lines) {
    const trimmed = line.trim();

    // 已有 -webkit- 前缀的行跳过
    if (/^\s*-(webkit|moz|ms)-/.test(line)) {
      out.push(line);
      continue;
    }

    for (const [pattern, build] of PREFIX_RULES) {
      const match = pattern.exec(trimmed);
      if (match) {
        const indent = line.slice(0, line.length - trimmed.length);
        out.push(build(indent, trimmed));
        out.push('\u0000SKIP'); // 占位，后面去掉原行
        break;
      }
    }

    if (out[out.length - 1] === '\u0000SKIP') {
      out.pop();
      continue;
    }

    out.push(line);
  }

  return out.join('\n');
}

// ---- 极简 CSS 压缩 ---------------------------------------------------------
// 压缩交给 gulp-clean-css 之外的自实现：去掉注释、多余空白，保留必要空格。

function minify(css) {
  return css
    // 去掉块注释（保留 /*! 开头的许可注释）
    .replace(/\/\*(?!\!)[\s\S]*?\*\//g, '')
    // 压缩空白
    .replace(/\s*([{}:;,>~+])\s*/g, '$1')
    .replace(/;}/g, '}')
    .replace(/\s+/g, ' ')
    .replace(/\s*\n\s*/g, '')
    .trim();
}

// ---------------------------------------------------------------------------
// Less -> CSS
// ---------------------------------------------------------------------------

async function compileStyles() {
  if (!fs.existsSync(ENTRY)) {
    throw new Error('找不到 Less 入口文件：' + ENTRY);
  }

  const source = fs.readFileSync(ENTRY, 'utf8');

  const result = await less.render(source, {
    filename: ENTRY,
    paths: [LESS_DIR],
    javascriptEnabled: false,
    math: 'parens-division',
    compress: false,
    // 生成 sourcemap 供开发时定位到 .less 源码
    sourceMap: isDev ? { outputSourceFiles: true, sourceMapFileInline: false } : undefined,
    modifyVars: {
      'lolilota-version': JSON.stringify(pkg.version)
    }
  });

  const banner = `/*! ${pkg.name} v${pkg.version} | ${pkg.description} | 由 src/less/style.less 编译，请勿直接修改本文件 */\n`;

  let css = autoprefix(result.css);
  const unminified = css.length;

  if (!isDev) {
    css = banner + minify(css);
  } else {
    css = banner + css + (result.map ? `\n/*# sourceMappingURL=lolilota.css.map */\n` : '');
  }

  const gzipped = zlib.gzipSync(Buffer.from(css)).length;

  // 主产物
  const wroteCss = writeFileSafe(OUT_CSS, css);
  const wroteDist = writeFileSafe(OUT_DIST_CSS, css);

  if (isDev && result.map) {
    writeFileSafe(OUT_CSS + '.map', result.map);
    writeFileSafe(OUT_DIST_CSS + '.map', result.map);
  }

  const report = [
    `  Less  ${path.relative(ROOT, ENTRY)}  (${fs.readdirSync(LESS_DIR).length} 个源文件目录项)`,
    `  CSS   ${path.relative(ROOT, OUT_CSS)}  ${kb(unminified)} -> ${kb(css.length)}  gzip ${kb(gzipped)}${isDev ? '  [dev]' : ''}`
  ];

  if (!wroteCss) {
    report.push('  ! css/lolilota.css 写入被拒绝（文件被占用或权限受限），已跳过');
  }
  if (!wroteDist) {
    report.push('  ! dist/css/lolilota.css 写入被拒绝，已跳过');
  }

  console.log(report.join('\n'));

  return css;
}

// ---------------------------------------------------------------------------
// dist 打包
// ---------------------------------------------------------------------------

function loadManifest() {
  const raw = fs.readFileSync(path.join(ROOT, 'manifest.json'), 'utf8');
  const cleaned = raw.replace(/^\uFEFF/, '');
  return JSON.parse(cleaned);
}

/**
 * 生成扩展清单（委托给 bin/manifest.php）
 *
 * manifest.json 的路径以 dist 为基准，PHP 脚本会派生出
 * dist/manifest.json 与 manifest.root.json，并做校验。
 *
 * @returns {boolean} 是否成功
 */
function generateManifest() {
  const script = path.join(ROOT, 'bin', 'manifest.php');

  if (!fs.existsSync(script)) {
    console.log('  manifest  跳过（找不到 bin/manifest.php）');
    return false;
  }

  const php = process.env.PHP_BINARY || 'php';
  const result = spawnSync(php, [script], { cwd: ROOT, stdio: 'inherit' });

  if (result.error || result.status !== 0) {
    console.error('  \x1b[31m✗\x1b[0m manifest 生成或校验失败');
    return false;
  }

  console.log('  manifest  dist/manifest.json + manifest.root.json');
  return true;
}

/** HTML / manifest 里的占位符替换 */
function renderText(text, extra = {}) {
  const tokens = {
    __NAME__: 'Lolilota',
    __CNAME__: '洛丽洛塔',
    __VERSION__: pkg.version,
    __DESCRIPTION__: pkg.description,
    __AUTHOR__: pkg.author,
    __ASSET_CSS__: 'css/lolilota.css',
    __ASSET_LIB__: 'lib/jcupupw.umd.js',
    __ASSET_UTIL__: 'js/util.js',
    __ASSET_CONFIG__: 'js/config.js',
    __ASSET_API__: 'js/api.js',
    __ASSET_HISTORY__: 'js/history.js',
    __ASSET_SYNC__: 'js/sync.js',
    __ASSET_AUTH__: 'js/auth.js',
    __ASSET_ACCOUNT__: 'js/account.js',
    __ASSET_MAIN__: 'js/script.js',
    __BOOT_SCRIPT__: '<!-- 扩展形态：后端地址通过账号面板或 ?api= 参数配置 -->',
    ...extra
  };

  let out = text;
  for (const [key, value] of Object.entries(tokens)) {
    out = out.split(key).join(String(value));
  }
  return out;
}

function buildDist() {
  const copied = [];

  // 脚本
  for (const file of walk(path.join(SRC, 'js'), (f) => f.endsWith('.js'))) {
    const rel = path.relative(path.join(SRC, 'js'), file);
    if (copyFileSafe(file, path.join(DIST, 'js', rel))) copied.push('js/' + rel.split(path.sep).join('/'));
  }

  // 第三方库
  for (const file of walk(path.join(SRC, 'lib'))) {
    const rel = path.relative(path.join(SRC, 'lib'), file);
    if (copyFileSafe(file, path.join(DIST, 'lib', rel))) copied.push('lib/' + rel.split(path.sep).join('/'));
  }

  // 图标
  for (const file of walk(path.join(SRC, 'icons'))) {
    const rel = path.relative(path.join(SRC, 'icons'), file);
    if (copyFileSafe(file, path.join(DIST, 'icons', rel))) copied.push('icons/' + rel.split(path.sep).join('/'));
  }

  // 页面
  for (const file of walk(path.join(SRC, 'html'), (f) => f.endsWith('.html'))) {
    const html = renderText(fs.readFileSync(file, 'utf8'));
    const target = path.join(DIST, path.basename(file));
    if (writeFileSafe(target, html)) copied.push(path.basename(file));
  }

  // 扩展清单：交给 bin/manifest.php 生成（同一套实现，避免两处逻辑漂移）。
  // 它会写出 dist/manifest.json（dist 加载用）与 manifest.root.json（仓库根加载用），
  // 并校验 homepage 是否为绝对 URL、引用文件是否存在。
  if (!generateManifest()) {
    process.exitCode = 1;
  }

  console.log(`  dist  ${copied.length} 个文件 -> ${path.relative(ROOT, DIST)}/`);

  pruneDist(copied);

  return copied;
}

/** 删除 dist 里不属于本次构建的残留文件（例如产物改名后的旧文件） */
function pruneDist(copied) {
  const expected = new Set(copied);

  // CSS 由 buildStyles 写入，单独登记
  for (const css of ['css/lolilota.css', 'css/lolilota.css.map']) {
    if (fs.existsSync(path.join(DIST, css))) expected.add(css);
  }

  let removed = 0;
  for (const file of walk(DIST)) {
    const rel = path.relative(DIST, file).split(path.sep).join('/');
    if (expected.has(rel)) continue;

    try {
      fs.unlinkSync(file);
      removed++;
    } catch (e) {
      /* 删不掉就留着 */
    }
  }

  if (removed > 0) {
    console.log(`  dist  清理 ${removed} 个残留文件`);
  }
}

/**
 * 把构建产物同步给 PHP 站点根目录
 *
 * public/ 是混合目录：
 *   - 手写后端源码：index.php / api.php / router.php / bootstrap.php
 *   - 由 dist 同步的前端资源：css/ js/ lib/ icons/
 *   - newtab.html：作为 index.php 的渲染模板（占位符在渲染时替换）
 *
 * 刻意**不同步** manifest.json：它是浏览器扩展清单，站点不需要公开。
 * 同步只覆盖上述固定清单，PHP 源码不在其中，天然不会被误删。
 */
const PUBLISH_DIRS = ['css', 'js', 'lib', 'icons'];

function publishToPublic() {
  if (!fs.existsSync(DIST)) return 0;

  let count = 0;

  // 资源目录
  for (const dir of PUBLISH_DIRS) {
    const source = path.join(DIST, dir);
    if (!fs.existsSync(source)) continue;
    count += syncDir(source, path.join(PUBLIC, dir));
  }

  // 页面模板（index.php 读取它并注入运行时配置）
  const template = path.join(DIST, 'newtab.html');
  if (fs.existsSync(template) && copyFileSafe(template, path.join(PUBLIC, 'newtab.html'))) {
    count++;
  }

  console.log(`  pub   ${count} 个前端文件 -> ${path.relative(ROOT, PUBLIC)}/`);
  return count;
}

// ---------------------------------------------------------------------------
// 主流程
// ---------------------------------------------------------------------------

async function buildOnce() {
  console.log(`\n  Lolilota 构建 ${isDev ? '(开发)' : '(生产)'}  v${pkg.version}\n`);
  await compileStyles();
  if (withDist) {
    buildDist();
    publishToPublic();
  }
  console.log('\n  ✔ 构建完成\n');
}

function startWatch() {
  console.log('  监听 src/less 与 src/js 变化，Ctrl+C 结束…\n');
  let timer = null;
  let pending = false;

  const run = async () => {
    if (pending) return;
    pending = true;
    try {
      await buildOnce();
    } catch (err) {
      console.error('  编译失败：' + (err && err.message ? err.message : err));
    } finally {
      pending = false;
    }
  };

  const trigger = () => {
    clearTimeout(timer);
    timer = setTimeout(run, 120);
  };

  for (const dir of [LESS_DIR, path.join(SRC, 'js'), path.join(SRC, 'html')]) {
    if (fs.existsSync(dir)) {
      fs.watch(dir, { recursive: true }, trigger);
    }
  }
}

(async () => {
  try {
    if (isWatch) {
      await buildOnce();
      startWatch();
    } else {
      await buildOnce();
    }
  } catch (err) {
    console.error('\n  ✗ 构建失败：' + (err && err.message ? err.message : err));
    if (args.includes('--trace') && err && err.stack) {
      console.error(err.stack);
    }
    process.exit(1);
  }
})();
