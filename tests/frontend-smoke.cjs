#!/usr/bin/env node
/**
 * 前端启动与搜索行为回归测试
 *
 * 为什么需要它：这个项目的脚本是「按顺序加载、挂到 window.Lolilota 命名空间」
 * 的经典多文件模式，模块之间还有相互引用（sync <-> auth）。一旦某个模块在
 * IIFE 顶部用 `var x = NS.x;` 捕获了尚未加载的模块，页面就会在启动时静默失效
 * ——最典型的表现就是「按回车 / 点搜索按钮没反应」。
 *
 * 本测试在 Node 里搭一个最小 DOM 仿真，按页面真实顺序执行 dist/js/*.js，
 * 然后断言：
 *   1. 每个脚本都能无异常执行完
 *   2. 输入框的 keydown 监听器已注册，按回车会打开 Bing 搜索
 *   3. 表单的 submit 监听器已注册，点击按钮会打开 Bing 搜索
 *
 * 用法： node tests/frontend-smoke.cjs
 * 退出码：0 通过，1 失败
 */
'use strict';

const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const ROOT = path.resolve(__dirname, '..');
const DIST = path.join(ROOT, 'dist');

// ---------------------------------------------------------------------------
// 最小 DOM 仿真
// ---------------------------------------------------------------------------

function makeElement(id, tag) {
  const listeners = new Map();

  const el = {
    id,
    tagName: String(tag || 'div').toUpperCase(),
    className: '',
    classList: {
      add() {}, remove() {}, toggle() {}, contains: () => false
    },
    style: {},
    dataset: {},
    children: [],
    innerHTML: '',
    textContent: '',
    value: '',
    hidden: false,
    disabled: false,
    files: null,
    offsetWidth: 100,
    offsetHeight: 20,
    parentNode: null,

    addEventListener(type, fn) {
      if (!listeners.has(type)) listeners.set(type, []);
      listeners.get(type).push(fn);
    },
    removeEventListener() {},
    listenerCount(type) {
      return (listeners.get(type) || []).length;
    },
    dispatch(type, event) {
      const list = listeners.get(type) || [];
      const ev = Object.assign(
        { type, preventDefault() {}, stopPropagation() {}, target: el },
        event || {}
      );
      for (const fn of list) fn(ev);
      return list.length;
    },

    appendChild(child) { child.parentNode = el; el.children.push(child); return child; },
    removeChild() {},
    remove() {},
    replaceChildren() {},
    querySelector: () => null,
    querySelectorAll: () => [],
    closest: () => null,
    contains: () => false,
    focus() {},
    select() {},
    click() { el.dispatch('click', {}); },
    setAttribute() {},
    getAttribute: () => null,
    insertAdjacentHTML() {},
    getBoundingClientRect: () => ({ left: 0, top: 0, width: 100, height: 20 })
  };

  return el;
}

const IDS = [
  'searchForm', 'q', 'suggest', 'historyBtn', 'historyCountPill', 'exportOpenBtn',
  'importBtn', 'clearBtn', 'importFile', 'aboutLink', 'accountFab', 'vbStage'
];

const elements = {};
for (const id of IDS) elements[id] = makeElement(id, id === 'searchForm' ? 'form' : 'div');
elements.importFile.type = 'file';

const document = {
  body: makeElement('body'),
  head: makeElement('head'),
  documentElement: makeElement('html'),
  activeElement: null,
  readyState: 'complete',
  getElementById: (id) => elements[id] || null,
  createElement: (tag) => makeElement('', tag),
  createTextNode: (t) => ({ textContent: t }),
  querySelector: () => null,
  querySelectorAll: () => [],
  addEventListener() {},
  removeEventListener() {}
};

const openedUrls = [];

function makeStorage() {
  const store = new Map();
  return {
    getItem: (k) => (store.has(k) ? store.get(k) : null),
    setItem: (k, v) => store.set(k, String(v)),
    removeItem: (k) => store.delete(k),
    clear: () => store.clear()
  };
}

const windowObject = {
  document,
  location: { search: '', href: 'http://127.0.0.1:8787/', origin: 'http://127.0.0.1:8787' },
  navigator: { userAgent: 'node-sim' },
  localStorage: makeStorage(),
  open(url) { openedUrls.push(url); return { closed: false }; },
  alert() {},
  confirm: () => true,
  prompt: () => null,
  setTimeout: (fn) => { if (typeof fn === 'function') fn(); return 0; },
  clearTimeout() {},
  setInterval: () => 0,
  clearInterval() {},
  requestAnimationFrame: (fn) => { fn(); return 0; },
  cancelAnimationFrame() {},
  addEventListener() {},
  removeEventListener() {},
  innerWidth: 1280,
  innerHeight: 800,

  // 后端注入的运行时配置（与 public/index.php 注入的形状一致）
  __LOLILOTA__: {
    apiBase: '',
    mode: 'site',
    providers: ['cdifit', 'local'],
    siteName: 'Cdifit 账号',
    appName: 'Lolilota',
    version: '1.1.0',
    homepage: 'https://lolilota.520world.top/',
    debug: false
  }
};

windowObject.window = windowObject;
windowObject.self = windowObject;
windowObject.globalThis = windowObject;

// 离线环境：网络请求一律失败，避免测试依赖外部服务
windowObject.fetch = () => Promise.reject(new Error('offline in test'));
windowObject.URL = URL;
windowObject.URLSearchParams = URLSearchParams;
windowObject.Blob = class {};
windowObject.FileReader = class { readAsText() {} };
windowObject.AbortController = class { constructor() { this.signal = null; } abort() {} };
windowObject.Promise = Promise;
windowObject.JSON = JSON;
windowObject.console = console;
windowObject.Node = class {};
windowObject.NodeList = class {};

const context = vm.createContext(windowObject);

// ---------------------------------------------------------------------------
// 断言工具
// ---------------------------------------------------------------------------

let passed = 0;
let failed = 0;

function check(label, condition, detail) {
  if (condition) {
    passed++;
    console.log('  \x1b[32m✓\x1b[0m ' + label + (detail ? '  ' + detail : ''));
  } else {
    failed++;
    console.log('  \x1b[31m✗\x1b[0m ' + label + (detail ? '  ' + detail : ''));
  }
}

// ---------------------------------------------------------------------------
// 1. 按页面顺序执行脚本
// ---------------------------------------------------------------------------

console.log('前端启动回归测试\n');
console.log('[1] 按页面顺序执行脚本');

const scripts = [
  'lib/jcupupw.umd.js',
  'js/util.js',
  'js/config.js',
  'js/api.js',
  'js/history.js',
  'js/sync.js',
  'js/auth.js',
  'js/account.js',
  'js/script.js'
];

for (const rel of scripts) {
  const file = path.join(DIST, rel);
  if (!fs.existsSync(file)) {
    check(rel + ' 存在', false, '找不到 dist/' + rel + '（是否忘了构建？）');
    continue;
  }

  try {
    vm.runInContext(fs.readFileSync(file, 'utf8'), context, { filename: rel });
    check(rel + ' 执行无异常', true);
  } catch (e) {
    check(rel + ' 执行无异常', false, e.name + ': ' + e.message);
  }
}

// ---------------------------------------------------------------------------
// 2. 模块是否都注册成功
// ---------------------------------------------------------------------------

console.log('\n[2] 命名空间模块注册');

const NS = windowObject.Lolilota || {};
for (const name of ['util', 'config', 'api', 'history', 'sync', 'auth', 'account', 'app']) {
  check('window.Lolilota.' + name, NS[name] !== undefined, typeof NS[name]);
}

// ---------------------------------------------------------------------------
// 3. 搜索交互
// ---------------------------------------------------------------------------

console.log('\n[3] 搜索交互');

// 关键回归点：加载顺序导致的依赖为 undefined 会让监听器注册不上
check(
  '输入框已注册 keydown 监听器',
  elements.q.listenerCount('keydown') > 0,
  '数量=' + elements.q.listenerCount('keydown')
);
check(
  '表单已注册 submit 监听器',
  elements.searchForm.listenerCount('submit') > 0,
  '数量=' + elements.searchForm.listenerCount('submit')
);

// 回车
elements.q.value = '回车测试';
openedUrls.length = 0;

let keydownError = null;
try {
  elements.q.dispatch('keydown', { key: 'Enter', preventDefault() {} });
} catch (e) {
  keydownError = e;
}

check('按回车不抛异常', keydownError === null, keydownError ? keydownError.message : '');
check(
  '按回车打开 Bing 搜索',
  openedUrls.length === 1 && openedUrls[0].includes('bing.com/search?q='),
  openedUrls[0] || '(没有打开任何窗口)'
);
check(
  '回车搜索词正确编码',
  openedUrls.length === 1 && openedUrls[0].includes(encodeURIComponent('回车测试')),
  openedUrls[0] || ''
);

// 提交按钮（触发表单 submit）
elements.q.value = '按钮测试';
openedUrls.length = 0;

let submitError = null;
try {
  elements.searchForm.dispatch('submit', { preventDefault() {} });
} catch (e) {
  submitError = e;
}

check('点击搜索按钮不抛异常', submitError === null, submitError ? submitError.message : '');
check(
  '点击搜索按钮打开 Bing 搜索',
  openedUrls.length === 1 && openedUrls[0].includes('bing.com/search?q='),
  openedUrls[0] || '(没有打开任何窗口)'
);

// 空关键词不应该打开窗口
elements.q.value = '   ';
openedUrls.length = 0;
try {
  elements.searchForm.dispatch('submit', { preventDefault() {} });
} catch (e) { /* 提示输入即可 */ }
check('空关键词不发起搜索', openedUrls.length === 0, '打开数量=' + openedUrls.length);

// ---------------------------------------------------------------------------
// 4. 搜索历史被记录
// ---------------------------------------------------------------------------

console.log('\n[4] 搜索历史');

elements.q.value = '历史记录测试';
openedUrls.length = 0;
try {
  elements.searchForm.dispatch('submit', { preventDefault() {} });
} catch (e) { /* ignore */ }

const historyApi = NS.history;
const stored = historyApi ? historyApi.get() : [];
check(
  '搜索后写入本地历史',
  stored.some((item) => item && item.text === '历史记录测试'),
  '共 ' + stored.length + ' 条'
);

// ---------------------------------------------------------------------------
// 结果
// ---------------------------------------------------------------------------

console.log('');
if (failed === 0) {
  console.log('\x1b[32m前端启动与搜索行为正常\x1b[0m（' + passed + ' 项）');
  process.exit(0);
}

console.log('\x1b[31m前端测试未通过：' + failed + ' 项失败，' + passed + ' 项通过\x1b[0m');
process.exit(1);
