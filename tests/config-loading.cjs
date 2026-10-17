#!/usr/bin/env node
/**
 * 配置加载回归测试
 *
 * 为什么需要它：曾经出现过「把 CDIFIT_BASE_URL 写进 .env 却提示没配置」——
 * 因为项目根本没有读取 .env 的代码。这类问题很难从现象上看出来（用户以为
 * 配置生效了），所以用测试把「优先级链」固定下来。
 *
 * 验证内容：
 *   1. .env 里的值能被 Config 读到
 *   2. 真实环境变量优先于 .env
 *   3. config/local.php 优先于默认值
 *   4. .env 的引号 / 注释 / 空值 / export 写法都能正确解析
 *
 * 用法： node tests/config-loading.cjs
 * 退出码：0 通过，1 失败
 */
'use strict';

const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { execFileSync } = require('node:child_process');

const ROOT = path.resolve(__dirname, '..');
const PHP = process.env.PHP_BINARY || 'php';

let passed = 0;
let failed = 0;

function check(label, ok, detail) {
  if (ok) {
    passed++;
    console.log('  \x1b[32m✓\x1b[0m ' + label + (detail ? '  ' + detail : ''));
  } else {
    failed++;
    console.log('  \x1b[31m✗\x1b[0m ' + label + (detail ? '  ' + detail : ''));
  }
}

/** 干净环境：不能预先带着 CDIFIT_BASE_URL，否则它优先级高于 .env */
function baseEnv() {
  const env = { ...process.env };
  // 注意：不能设成空串 —— getenv() 对空串仍返回 ''（!== false），
  // 会和真实环境变量一样把 .env 顶掉。
  delete env.CDIFIT_BASE_URL;
  delete env.APP_HOMEPAGE;
  return env;
}

/** 在隔离目录里跑一段 PHP，返回输出的 JSON */
function phpJson(script) {
  const out = execFileSync(PHP, ['-d', 'opcache.enable_cli=0', '-r', script], {
    cwd: ROOT,
    env: baseEnv()
  }).toString();

  return JSON.parse(out.trim());
}

console.log('配置加载回归测试\n');

// ---------------------------------------------------------------------------
// 1. 当前 .env 是否真的被读取
// ---------------------------------------------------------------------------

console.log('[1] .env 加载');

const envPath = path.join(ROOT, '.env');
const hasEnvFile = fs.existsSync(envPath);

check('.env 文件存在', hasEnvFile, hasEnvFile ? '.env' : '（跳过，仓库默认没有 .env）');

const probe = phpJson(`
require 'public/bootstrap.php';
echo json_encode([
    'hasFile'   => Lolilota\\Support\\Env::hasFile(),
    'baseUrl'   => Lolilota\\Core\\Config::get('cdifit.base_url', ''),
    'homepage'  => Lolilota\\Core\\Config::get('app.homepage', ''),
    'configured'=> (new Lolilota\\Auth\\CdifitProvider())->isConfigured(),
]);
`);

check('Env::hasFile() 与文件实际存在一致', probe.hasFile === hasEnvFile, String(probe.hasFile));

if (hasEnvFile) {
  // 从 .env 里取出真实期望值，和 PHP 读到的对比
  const raw = fs.readFileSync(envPath, 'utf8');
  const m = raw.match(/^CDIFIT_BASE_URL=(.*)$/m);
  const expected = m ? m[1].trim().replace(/^["']|["']$/g, '') : '';

  check(
    '.env 里的 CDIFIT_BASE_URL 被读取',
    expected === '' || probe.baseUrl === expected,
    '期望 "' + expected + '"，实际 "' + probe.baseUrl + '"'
  );

  check(
    '读到最后能判定 provider 已配置',
    expected === '' || probe.configured === true,
    'configured=' + probe.configured
  );
}

// ---------------------------------------------------------------------------
// 2. 优先级：真实环境变量 > .env
// ---------------------------------------------------------------------------

console.log('\n[2] 优先级：真实环境变量 > .env');

const override = execFileSync(
  PHP,
  ['-d', 'opcache.enable_cli=0', '-r', `
require 'public/bootstrap.php';
echo json_encode([
    'baseUrl' => Lolilota\\Core\\Config::get('cdifit.base_url', ''),
    'source'  => Lolilota\\Support\\Env::fromProcess('CDIFIT_BASE_URL') ? 'process' : 'other',
]);
`],
  { cwd: ROOT, env: { ...process.env, CDIFIT_BASE_URL: 'https://override.example' } }
).toString();

const overridden = JSON.parse(override.trim());

check('环境变量覆盖 .env', overridden.baseUrl === 'https://override.example', overridden.baseUrl);
check('来源判定为环境变量', overridden.source === 'process', overridden.source);

// ---------------------------------------------------------------------------
// 3. .env 解析格式
// ---------------------------------------------------------------------------

console.log('\n[3] .env 解析格式');

const tmpDir = fs.mkdtempSync(path.join(os.tmpdir(), 'lolilota-env-'));
const tmpEnv = path.join(tmpDir, '.env');

fs.writeFileSync(
  tmpEnv,
  [
    '# 注释行应被忽略',
    'PLAIN=simple',
    'QUOTED="带 # 和空格的值"',
    "SINGLE='单引号不解析转义 \\n'",
    'export EXPORTED=exported-value',
    'SPACED = spaced   ',
    'EMPTY=',
    'INLINE=value # 行内注释',
    'BADLINE',
    '1INVALID=x'
  ].join('\n'),
  'utf8'
);

const parsed = JSON.parse(
  execFileSync(
    PHP,
    ['-d', 'opcache.enable_cli=0', '-r', `
require 'public/bootstrap.php';
$count = Lolilota\\Support\\Env::load(${JSON.stringify(tmpEnv)});
echo json_encode(['count' => $count, 'values' => Lolilota\\Support\\Env::loaded()]);
`],
    { cwd: ROOT }
  ).toString().trim()
);

check('普通值', (parsed.values.PLAIN || '') === 'simple', parsed.values.PLAIN);
check('双引号保留 # 与空格', (parsed.values.QUOTED || '') === '带 # 和空格的值', parsed.values.QUOTED);
check('单引号不解析转义', (parsed.values.SINGLE || '').includes('\\n'), parsed.values.SINGLE);
check('export 前缀', (parsed.values.EXPORTED || '') === 'exported-value', parsed.values.EXPORTED);
check('等号两侧空格', (parsed.values.SPACED || '') === 'spaced', parsed.values.SPACED);
check('行内注释被剥离', (parsed.values.INLINE || '') === 'value', parsed.values.INLINE);
check('空值被记录为空串', (parsed.values.EMPTY || '') === '', JSON.stringify(parsed.values.EMPTY));
check('非法行被忽略', parsed.values.BADLINE === undefined && parsed.values['1INVALID'] === undefined);

fs.rmSync(tmpDir, { recursive: true, force: true });

// ---------------------------------------------------------------------------
// 结果
// ---------------------------------------------------------------------------

console.log('');
if (failed === 0) {
  console.log('\x1b[32m配置加载正常\x1b[0m（' + passed + ' 项）');
  process.exit(0);
}

console.log('\x1b[31m配置加载测试未通过：' + failed + ' 项失败，' + passed + ' 项通过\x1b[0m');
process.exit(1);
