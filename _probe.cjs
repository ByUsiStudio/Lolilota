// 临时诊断脚本：复现 gulp-less 的调用方式（用完即删）
const fs = require('node:fs');
const path = require('node:path');
const less = require('less');

const filePath = path.resolve('src/less/main.less');
const str = fs.readFileSync(filePath, 'utf8');

const optionSets = {
  'gulpfile 当前配置': {
    paths: [path.resolve('src/less')],
    javascriptEnabled: false,
    math: 'parens-division',
    modifyVars: { 'lolilota-version': '"1.1.0"' },
    filename: filePath
  },
  '仅 filename': { filename: filePath },
  'filename + paths(相对)': { filename: filePath, paths: ['src/less'] },
  'filename + math': { filename: filePath, math: 'parens-division' },
  'filename + modifyVars': { filename: filePath, modifyVars: { 'lolilota-version': '"1.1.0"' } },
  'filename + javascriptEnabled:false': { filename: filePath, javascriptEnabled: false }
};

(async () => {
  for (const [label, extra] of Object.entries(optionSets)) {
    try {
      const out = await less.render(str, Object.assign({}, extra));
      console.log('OK  ', label, '->', out.css.length);
    } catch (err) {
      console.log('ERR ', label, '->', String(err.message).split('\n')[0]);
    }
  }
})();
