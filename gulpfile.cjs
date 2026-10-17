/**
 * Lolilota 构建脚本
 *
 * 职责：
 *   1. 把 src/less/**\/*.less 编译成 css/*.css（Less -> CSS）
 *   2. 把前端资源整理进 dist/（浏览器扩展直接加载 dist 目录）
 *   3. 同步一份到 public/（PHP 后端的站点根目录），前后端同源部署
 *   4. 替换 HTML / manifest.json 中的占位符
 *
 * 用法：
 *   npm run build       生产构建（压缩 CSS）
 *   npm run build:dev   开发构建（保留 sourcemap）
 *   npm run watch       监听源码变化并增量重建
 *   npm run dev         完整构建 + 监听，并提示如何启动 PHP 后端
 *   npm run clean       清理构建产物
 */
'use strict';

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
const PUBLIC = 'public';
const CSS_OUT = 'css';

const isDev = process.argv.includes('--dev');
const target = process.env.TARGET || 'all'; // all | chromium | firefox

/** Less 变量注入：样式文件里可直接使用 @lolilota-version */
const lessVariables = {
    'lolilota-version': JSON.stringify(pkg.version)
};

/** 扩展形态：所有资源都是相对 dist/ 的路径 */
const EXT_ASSETS = {
    __ASSET_CSS__: 'css/style.css',
    __ASSET_LIB__: 'lib/jcupupw.umd.js',
    __ASSET_UTIL__: 'js/util.js',
    __ASSET_CONFIG__: 'js/config.js',
    __ASSET_API__: 'js/api.js',
    __ASSET_HISTORY__: 'js/history.js',
    __ASSET_SYNC__: 'js/sync.js',
    __ASSET_AUTH__: 'js/auth.js',
    __ASSET_ACCOUNT__: 'js/account.js',
    __ASSET_MAIN__: 'js/script.js',
    // 扩展不支持服务端注入，留空（config.js 会回落到默认值 / 本地存储）
    __BOOT_SCRIPT__: '<!-- 扩展形态：后端地址通过账号面板或 ?api= 参数配置 -->'
};

/** 通用文本占位符 */
const TEXT_TOKENS = {
    __NAME__: 'Lolilota',
    __CNAME__: '洛丽洛塔',
    __VERSION__: pkg.version,
    __DESCRIPTION__: pkg.description,
    __AUTHOR__: pkg.author
};

/** 文本替换流：先换资源路径，再换通用token，最后按目标浏览器裁剪 manifest */
function applyPlaceholders(options) {
    const extra = (options && options.extra) || {};

    return new Transform({
        objectMode: true,
        transform(file, _enc, cb) {
            if (file.isNull()) return cb(null, file);

            let text = file.contents.toString('utf8');
            const tokens = Object.assign({}, EXT_ASSETS, TEXT_TOKENS, extra);

            for (const [key, value] of Object.entries(tokens)) {
                text = text.split(key).join(String(value));
            }

            // Firefox 专有字段：Chromium 构建时移除，避免安装告警
            if (target === 'chromium') {
                text = text.replace(
                    /,\s*"browser_specific_settings"\s*:\s*\{[\s\S]*?\n\s{2}\}/,
                    ''
                );
            }

            file.contents = Buffer.from(text, 'utf8');
            cb(null, file);
        }
    });
}

function cssPlugins() {
    const plugins = [
        less({
            paths: [path.join(__dirname, SRC, 'less')],
            javascriptEnabled: false,
            math: 'parens-division',
            modifyVars: lessVariables
        })
    ];

    if (!isDev) {
        plugins.push(
            cleanCss(
                { level: { 1: { specialComments: 0 }, 2: { mergeSemantically: true } } },
                (details) => {
                    process.stdout.write(
                        `  css   ${details.name}  ${(details.stats.originalSize / 1024).toFixed(1)}KB -> ` +
                        `${(details.stats.minifiedSize / 1024).toFixed(1)}KB\n`
                    );
                }
            )
        );
    }

    return plugins;
}

/**
 * Less 源码匹配规则
 *
 * 只有入口文件需要编译；下划线开头的局部文件（_variables/_base/...）
 * 是给入口 @import 用的，单独编译必然报“变量未定义”，必须排除。
 */
const LESS_ENTRY_GLOB = [path.join(SRC, 'less', '*.less'), '!' + path.join(SRC, 'less', '_*.less')];

/** Less -> css/ 与 dist/css/ */
function styles() {
    return gulp
        .src(LESS_ENTRY_GLOB, { sourcemaps: isDev })
        .pipe(...cssPlugins())
        .pipe(rename({ dirname: '' }))
        .pipe(gulp.dest(CSS_OUT, { sourcemaps: isDev ? '.' : false }))
        .pipe(gulp.dest(path.join(DIST, 'css')));
}

/** 前端运行脚本 */
function scripts() {
    return gulp
        .src(path.join(SRC, 'js', '**', '*.js'), { base: path.join(SRC, 'js') })
        .pipe(gulp.dest(path.join(DIST, 'js')));
}

/** 第三方库 */
function vendor() {
    return gulp
        .src(path.join(SRC, 'lib', '**', '*'), { base: path.join(SRC, 'lib') })
        .pipe(gulp.dest(path.join(DIST, 'lib')));
}

/** 图标 */
function icons() {
    return gulp
        .src(path.join(SRC, 'icons', '**', '*'), { base: path.join(SRC, 'icons') })
        .pipe(gulp.dest(path.join(DIST, 'icons')));
}

/** 页面 */
function html() {
    return gulp
        .src(path.join(SRC, 'html', '*.html'))
        .pipe(applyPlaceholders())
        .pipe(gulp.dest(DIST));
}

/** 扩展清单 */
function manifest() {
    return gulp
        .src('manifest.json')
        .pipe(applyPlaceholders())
        .pipe(gulp.dest(DIST));
}

/** 把 dist/ 的前端资源同步给 PHP 站点（dist 仍保留，用于扩展加载） */
function publish() {
    return gulp
        .src([path.join(DIST, '**', '*')], { base: DIST, dot: true })
        .pipe(gulp.dest(PUBLIC));
}

/**
 * 清理构建产物
 *
 * 注意：某些环境下删除会因文件权限被拒（Windows 下的旧文件尤其常见）。
 * 这里对“删不掉”保持宽容——构建产物随后就会被重新写入覆盖，
 * 不应该因为清不掉一个文件就让整次构建失败。
 */
async function clean() {
    const targets = [
        DIST,
        path.join(CSS_OUT, '*.css'),
        path.join(CSS_OUT, '*.map')
    ];

    try {
        const removed = await deleteAsync(targets);
        process.stdout.write(`  clean  已删除 ${removed.length} 项\n`);
    } catch (err) {
        const blocked = (err && err.code === 'EPERM')
            || /EPERM|access is denied|拒绝访问/i.test(String(err && err.message));

        if (!blocked) {
            throw err;
        }

        process.stdout.write('  clean  部分文件无法删除（权限受限），改为覆盖写入后继续\n');
    }
}

const build = gulp.series(
    clean,
    gulp.parallel(styles, scripts, vendor, icons, html, manifest),
    publish
);

function watch() {
    gulp.watch(path.join(SRC, 'less', '**', '*.less'), gulp.series(styles, publish));
    gulp.watch(path.join(SRC, 'js', '**', '*.js'), gulp.series(scripts, publish));
    gulp.watch(path.join(SRC, 'lib', '**', '*'), gulp.series(vendor, publish));
    gulp.watch(path.join(SRC, 'html', '*.html'), gulp.series(html, publish));
    gulp.watch('manifest.json', gulp.series(manifest, publish));
}

function banner(done) {
    process.stdout.write(
        '\n  Lolilota 开发模式已启动\n' +
        '  - Less 改动自动编译到 css/ 与 dist/\n' +
        '  - PHP 后端请在另一个终端运行： npm run serve\n' +
        '  - 访问地址： http://127.0.0.1:8787\n' +
        '  - 浏览器扩展：加载 dist/ 目录（或仓库根目录，二者等价）\n\n'
    );
    done();
}

exports.styles = styles;
exports.scripts = scripts;
exports.vendor = vendor;
exports.icons = icons;
exports.html = html;
exports.manifest = manifest;
exports.publish = publish;
exports.clean = clean;
exports.build = build;
exports.watch = watch;
exports.dev = gulp.series(build, gulp.parallel(watch, banner));
exports.default = build;
