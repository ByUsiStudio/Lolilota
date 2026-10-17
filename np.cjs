#!/usr/bin/env node
/**
 * Lolilota 构建入口（无需 gulp-cli）
 *
 * 为什么不直接用 gulp 命令：gulp 命令行底层依赖 v8flags，它会 spawn 一个
 * Node 子进程并读取管道输出。部分受限环境（AppContainer / 沙箱）禁止创建
 * 管道，会直接报 spawn EPERM。这里改为在同一进程里调用 gulpfile.cjs 导出的
 * 任务函数，行为完全一致，但不产生任何子进程。
 *
 * 用法（等价于 gulp <task>）：
 *   node np.cjs            默认 build
 *   node np.cjs build
 *   node np.cjs build --dev
 *   node np.cjs watch
 *   node np.cjs clean
 *   node np.cjs dev
 *   node np.cjs list       列出可用任务
 */
'use strict';

const gulp = require('gulp');
const tasks = require('./gulpfile.cjs');

const names = Object.keys(tasks).filter((key) => key !== 'default');

function run(name) {
  const task = tasks[name];

  if (typeof task !== 'function') {
    process.stderr.write(`未知任务：${name}\n可用任务：${names.join(', ')}\n`);
    process.exit(1);
  }

  // 包一层，保证回调式任务也能正常结束
  const done = gulp.series(task);
  return new Promise((resolve, reject) => {
    done((err) => (err ? reject(err) : resolve()));
  });
}

const arg = process.argv[2];
const name = !arg || arg.startsWith('-') ? 'build' : arg;

if (name === 'list' || name === '--tasks') {
  process.stdout.write('可用任务：\n');
  for (const key of names) {
    process.stdout.write(`  - ${key}\n`);
  }
  process.exit(0);
}

run(name)
  .then(() => {
    process.stdout.write(`\n  ✔ ${name} 完成\n\n`);
  })
  .catch((err) => {
    process.stderr.write(`\n  ✗ ${name} 失败：${err && err.message ? err.message : err}\n\n`);
    if (err && err.stack && process.argv.includes('--trace')) {
      process.stderr.write(err.stack + '\n');
    }
    process.exit(1);
  });
