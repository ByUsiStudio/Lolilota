#!/usr/bin/env node
/**
 * 浏览器扩展包完整性校验
 *
 * 模拟浏览器加载扩展：校验两种加载方式下 manifest 引用的每个文件都存在，
 * 并顺着 newtab.html 里的 <link>/<script> 继续检查二级引用。
 *
 *   A. 加载 dist/      -> dist/manifest.json
 *   B. 加载仓库根目录   -> manifest.json
 *
 * 用法： node tests/extension-check.cjs
 * 退出码：0 通过，1 有问题
 */
'use strict';

const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.resolve(__dirname, '..');
let failures = 0;
let checks = 0;

function fail(msg) {
  failures++;
  console.log('  \x1b[31m✗\x1b[0m ' + msg);
}

function ok(msg) {
  checks++;
  console.log('  \x1b[32m✓\x1b[0m ' + msg);
}

/** 判断是否为绝对 URL / data URL / 站内绝对路径 */
function isExternal(p) {
  return /^[a-zA-Z][\w+.-]*:/.test(p) || p.startsWith('//') || p.startsWith('/');
}

/**
 * 校验一份 manifest
 * @param {string} manifestPath manifest 文件绝对路径
 * @param {string} baseDir      该 manifest 的加载目录（相对路径解析基准）
 * @param {string} label        报告用名称
 */
function checkManifest(manifestPath, baseDir, label) {
  console.log(`\n[${label}]  ${path.relative(ROOT, manifestPath) || manifestPath}`);

  if (!fs.existsSync(manifestPath)) {
    fail(`找不到 ${manifestPath}`);
    return;
  }

  let manifest;
  try {
    manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8').replace(/^\uFEFF/, ''));
    ok('JSON 合法');
  } catch (e) {
    fail('JSON 解析失败：' + e.message);
    return;
  }

  if (manifest.manifest_version !== 3) {
    fail('manifest_version 应为 3，实际为 ' + manifest.manifest_version);
  } else {
    ok('manifest_version = 3');
  }

  // homepage 必须是绝对 URL（这是本次报错的直接原因）
  const homepage = manifest.chrome_settings_overrides && manifest.chrome_settings_overrides.homepage;
  if (homepage !== undefined) {
    if (/^https?:\/\//i.test(homepage)) {
      ok('chrome_settings_overrides.homepage 是绝对 URL：' + homepage);
    } else {
      fail(`chrome_settings_overrides.homepage 必须是绝对 URL，当前为 "${homepage}"`
        + ' —— Chrome 会报 "Invalid value for overriding homepage url"');
    }
  } else {
    ok('未设置 homepage（可选）');
  }

  // 收集相对路径引用
  const refs = [];
  const push = (label2, value) => {
    if (typeof value === 'string' && value && !isExternal(value)) {
      refs.push([label2, value]);
    }
  };

  push('chrome_url_overrides.newtab', manifest.chrome_url_overrides && manifest.chrome_url_overrides.newtab);
  for (const [size, p] of Object.entries(manifest.icons || {})) push(`icons.${size}`, p);
  for (const section of ['action', 'options_ui', 'background']) {
    for (const key of ['default_popup', 'page', 'service_worker']) {
      if (manifest[section]) push(`${section}.${key}`, manifest[section][key]);
    }
  }

  for (const [name, rel] of refs) {
    const full = path.join(baseDir, rel);
    if (fs.existsSync(full)) {
      ok(`${name} -> ${rel}`);
    } else {
      fail(`${name} -> ${rel} 不存在（应为 ${path.relative(ROOT, full)}）`);
    }
  }

  // 顺着 newtab 页面继续检查二级引用
  const newtab = manifest.chrome_url_overrides && manifest.chrome_url_overrides.newtab;
  if (newtab && !isExternal(newtab) && fs.existsSync(path.join(baseDir, newtab))) {
    checkPage(path.join(baseDir, newtab), baseDir, path.dirname(path.join(baseDir, newtab)));
  }
}

/** 检查页面里的 link/script 引用 */
function checkPage(pagePath, loadBase, pageDir) {
  const html = fs.readFileSync(pagePath, 'utf8');

  // 页面里的相对路径是相对「页面所在目录」解析的
  const assets = [];
  const re = /(?:href|src)="([^"]+)"/g;
  let m;
  while ((m = re.exec(html)) !== null) {
    const value = m[1];
    if (!value || isExternal(value) || value.startsWith('#') || value === 'javascript:;') continue;
    assets.push(value);
  }

  if (assets.length === 0) {
    ok('页面没有相对资源引用');
    return;
  }

  let missing = 0;
  for (const asset of assets) {
    const full = path.join(pageDir, asset);
    if (!fs.existsSync(full)) {
      fail(`页面引用缺失：${path.relative(ROOT, pagePath)} -> ${asset}`);
      missing++;
    }
  }

  if (missing === 0) {
    ok(`页面 ${assets.length} 个资源引用全部存在`);
  }
}

// ---- 三种使用方式 ----------------------------------------------------------
//
//   manifest.json        事实来源（以 dist 为基准书写路径），
//                        同时也是「加载 dist/」时浏览器实际读取的那一份的逻辑来源
//   dist/manifest.json   「加载 dist/」实际读取
//   manifest.root.json   「加载仓库根目录」实际读取

checkManifest(path.join(ROOT, 'dist', 'manifest.json'), path.join(ROOT, 'dist'), 'A. 加载 dist/');
checkManifest(path.join(ROOT, 'manifest.root.json'), ROOT, 'B. 加载仓库根目录');
checkManifest(path.join(ROOT, 'manifest.json'), path.join(ROOT, 'dist'), 'C. 事实来源 manifest.json');

// 事实来源不应带 dist/ 前缀，否则会派生出 dist/dist/...
console.log('\n[前缀防呆]');
const source = JSON.parse(fs.readFileSync(path.join(ROOT, 'manifest.json'), 'utf8'));
const sourcePaths = [
  source.chrome_url_overrides && source.chrome_url_overrides.newtab,
  ...Object.values(source.icons || {})
].filter((v) => typeof v === 'string');

const doubled = sourcePaths.filter((p) => p.startsWith('dist/'));
if (doubled.length === 0) {
  ok('manifest.json 的路径以 dist 为基准（无 dist/ 前缀），不会派生 dist/dist/');
} else {
  fail('manifest.json 里出现了 dist/ 前缀：' + doubled.join(', ')
    + ' —— 会导致 dist/dist/... 这种错误路径');
}

// ---- 旧字段残留检查 --------------------------------------------------------
console.log('\n[字段检查]');
const distManifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'dist', 'manifest.json'), 'utf8'));

const hostPerms = distManifest.host_permissions || [];
if (hostPerms.some((p) => p.includes('127.0.0.1') || p.includes('localhost'))) {
  ok('已声明本机后端权限（默认 http://127.0.0.1:8787）');
} else {
  fail('缺少本机后端权限：扩展默认连不上 http://127.0.0.1:8787');
}

if ((distManifest.optional_host_permissions || []).length > 0) {
  ok('已声明可选域名权限（指向自建后端时申请）');
}

console.log('');
if (failures === 0) {
  console.log(`\x1b[32m扩展包校验通过\x1b[0m（${checks} 项）`);
  process.exit(0);
}

console.log(`\x1b[31m扩展包校验未通过：${failures} 个问题\x1b[0m`);
process.exit(1);
