/**
 * Lolilota 构建脚本
 *
 * 职责：
 *   1. 把 src/less/**\/*.less 编译成 css/*.css（Less -> CSS）
 *   2. 把前端静态资源整理进 dist/（扩展直接加载目录）
 *   3. 替换 HTML / manifest.json 里的占位符
 *
 * 用法：
 *   npm run build       生产构建（压缩 CSS，无 sourcemap）
 *   npm run build:dev   开发构建（保留 sourcemap，不压缩）
 *   npm run watch       监听 Less/JS 变化并增量重建
 *   npm run dev         watch + 提示如何启动 PHP 后端
 *   npm run clean       清理构建产物
 */
'use strict';

const fs = require('node:fs');
const path = require('node:path');
const { Transform } = require('node:stream');

const gulp = require('gulp');
const less = require('gulp-less');
const cleanCss = require('gulp-clean-css');
const rename = require('gulp-rename');
const { deleteAsync } = require('del');

const pkg = require('./package.json');

const SRC = 'src';
const DIST = 'dist';
const CSS_OUT = 'css';

const isDev = process.argv.includes('--dev');

/** Less 变量注入：让样式文件能读到版本号 */
const lessVariables = {
  'lolilota-version': JSON.stringify(pkg.version),
  'lolilota-name': JSON.stringify(pkg.name)
};

/** 页面/清单占位符表：HTML 与 manifest.json 共用 */
const placeholders = {
  __NAME__: 'Lolilota',
  __CNAME__: '洛丽洛塔',
  __VERSION__: pkg.version,
  __DESCRIPTION__: pkg.description,
  __AUTHOR__: pkg.author
};

/** 文本模板替换流 */
function applyPlaceholders() {
  return new Transform({
    objectMode: true,
    transform(file, _enc, cb) {
      if (file.isNull()) return cb(null, file);
      let text = file.contents.toString('utf8');
      for (const [key, value] of Object.entries(placeholders)) {
        text = text.split(key).join(String(value));
      }

      // Firefox 的 browser_specific_settings 只有 Firefox 需要，Chrome 会警告
      const keepGecko = process.env.TARGET !== 'chromium';
      if (!keepGecko) {
        text = text.replace(/\n\s*"browser_specific_settings"\s*:\s*\{[\s\S]*?\n\s*\}\s*(,?)/, (m, comma) => (comma ? '' : ''));
      }

      file.contents = Buffer.from(text, 'utf8');
      cb(null, file);
    }
  });
}

function cssPlugins() {
  const plugins = [less({ paths: [path.join(SRC, 'less')], javascriptEnabled: false, math: 'parens-division' })];
  if (!isDev) {
    plugins.push(
      cleanCss(
        { level: { 1: { specialComments: 0 }, 2: { mergeSemantically: true } } },
        (details) => {
          process.stdout.write(
            `  css  ${details.name}  ${(details.stats.originalSize / 1024).toFixed(1)}KB -> ` +
            `${(details.stats.minifiedSize / 1024).toFixed(1)}KB\n`
          );
        }
      )
    );
  }
  return plugins;
}

/**
 * Less -> CSS
 * 同时输出两份：css/（PHP 站点直接引用）与 dist/css/（扩展加载目录）
 */
function styles() {
  return gulp
    .src(path.join(SRC, 'less', '*.less'), { sourcemaps: isDev })
    .pipe(...cssPlugins())
    .pipe(rename({ dirname: '' }))
    .pipe(gulp.dest(CSS_OUT, { sourcemaps: isDev ? '.' : false }))
    .pipe(gulp.dest(path.join(DIST, 'css')));
}

/** Less 编译结果只进 dist（扩展打包用，避免重复写 css/） */
function stylesDist() {
  return gulp
    .src(path.join(SRC, 'less', '*.less'), { sourcemaps: isDev })
    .pipe(...cssPlugins())
    .pipe(rename({ dirname: '' }))
    .pipe(gulp.dest(path.join(DIST, 'css'), { sourcemaps: isDev ? '.' : false }));
}

/** 前端运行脚本 */
function scripts() {
  return gulp.src(path.join(SRC, 'js', '**', '*.js'), { base: path.join(SRC, 'js') }).pipe(gulp.dest(path.join(DIST, 'js')));
}

/** 第三方库（JCuPupw 等） */
function vendor() {
  return gulp.src(path.join(SRC, 'lib', '**', '*'), { base: path.join(SRC, 'lib') }).pipe(gulp.dest(path.join(DIST, 'lib')));
}

/** 图标 */
function icons() {
  return gulp.src(path.join(SRC, 'icons', '**', '*'), { base: path.join(SRC, 'icons') }).pipe(gulp.dest(path.join(DIST, 'icons')));
}

/** 页面模板 */
function html() {
  return gulp
    .src(path.join(SRC, 'html', '*.html'))
    .pipe(applyPlaceholders())
    .pipe(gulp.dest(DIST));
}

/** 扩展清单 */
function manifest() {
  return gulp.src('manifest.json').pipe(applyPlaceholders()).pipe(gulp.dest(DIST));
}

/** 清理产物 */
function clean() {
  return deleteAsync([DIST, path.join(CSS_OUT, '*.css'), path.join(CSS_OUT, '*.map')]);
}

const build = gulp.series(clean, gulp.parallel(styles, scripts, vendor, icons, html, manifest));

function watch() {
  gulp.watch(path.join(SRC, 'less', '**', '*.less'), styles);
  gulp.watch(path.join(SRC, 'js', '**', '*.js'), scripts);
  gulp.watch(path.join(SRC, 'html', '*.html'), html);
  gulp.watch('manifest.json', manifest);
}

function dev(done) {
  process.stdout.write(
    '\n  Lolilota 开发模式\n' +
    '  - Less 改动会自动编译到 css/ + dist/css/\n' +
    '  - PHP 后端请另开终端运行： npm run serve  (http://127.0.0.1:8787)\n\n'
  );
  done();
}

exports.styles = styles;
exports.stylesDist = stylesDist;
exports.scripts = scripts;
exports.vendor = vendor;
exports.icons = icons;
exports.html = html;
exports.manifest = manifest;
exports.clean = clean;
exports.build = build;
exports.watch = watch;
exports.dev = gulp.series(build, gulp.parallel(watch, dev));
exports.default = build;
