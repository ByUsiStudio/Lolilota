// 临时诊断脚本：直接喂给 gulp-less 插件，定位差异（用完即删）
const fs = require('node:fs');
const path = require('node:path');
const Vinyl = require('vinyl');
const less = require('gulp-less');

const filePath = path.resolve('src/less/main.less');
const file = new Vinyl({
  cwd: process.cwd(),
  base: path.resolve('src/less'),
  path: filePath,
  contents: fs.readFileSync(filePath)
});

const plugin = less({
  paths: [path.resolve('src/less')],
  javascriptEnabled: false,
  math: 'parens-division',
  modifyVars: { 'lolilota-version': '"1.1.0"' }
});

plugin.on('data', (out) => {
  console.log('OK  编译成功', out.path, out.contents.length, 'bytes');
});
plugin.on('error', (err) => {
  console.log('ERR ', String(err.message).split('\n')[0]);
});
plugin.end(file);
