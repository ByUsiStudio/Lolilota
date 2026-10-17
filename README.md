# Lolilota 洛丽洛塔

简洁清新的 Bing 搜索启始页：实时搜索建议、本地搜索历史（导入 / 导出 / 跨设备同步），
以及基于 **Cdifit** 账号系统的登录。

同一个前端可以两种形态运行：

| 形态 | 入口 | 说明 |
| --- | --- | --- |
| 浏览器扩展 | `manifest.json` → `dist/newtab.html` | 接管新标签页与主页，后端地址可在扩展内配置 |
| PHP 站点 | `public/index.php` | 同源部署，后端地址自动注入，登录体验最完整 |

技术栈：**Node.js（Less 构建链）+ Less（样式）+ 原生 JS（前端）+ PHP 8.1+（后端服务）**

---

## 1. 快速开始

两条路，**只装 PHP 也能完整跑起来**（`dist/` 与 `css/lolilota.css` 已经提交进仓库）：

```bash
# ---------- 方式 A：只要 PHP（不需要 Node.js）----------
php bin/serve.php                     # http://127.0.0.1:8787
php bin/serve.php --dev               # 开发模式：改 .less 刷新即见
php bin/build.php                     # 改完样式后重新生成 css/ 与 dist/css/

# Windows 也可以直接双击 / 命令行：
bin\serve.cmd                         # 或 bin\serve.cmd 8080 / dev / lan
powershell -ExecutionPolicy Bypass -File bin\serve.ps1 -Dev

# Linux / macOS：
chmod +x bin/serve.sh && ./bin/serve.sh dev

# ---------- 方式 B：用 Node 做完整打包 ----------
npm install                           # 只装一个 less
npm run build                         # 编译 Less + 打包 dist/ + 同步 public/
npm run dev                           # 监听 src/ 自动重建
```

打开页面与安装扩展：

```bash
# 站点形态
http://127.0.0.1:8787

# 扩展形态（无需任何构建）
Chrome/Edge -> 扩展页 -> 打开「开发者模式」-> 加载已解压的扩展 -> 选择 dist/ 目录
```

> **加载 `dist/` 目录**（不要加载仓库根目录）。
> `dist/` 是自包含的扩展包：里面有自己的 `manifest.json`，页面与资源路径都相对它解析。
> 仓库根目录不能当扩展加载 —— 一个目录里只能有一个 `manifest.json`，
> 而根目录的 `manifest.json` 是用于生成 `dist/manifest.json` 的**源文件**。

常用命令：

| 命令 | 作用 |
| --- | --- |
| `php bin/serve.php [--dev]` | 启动 PHP 服务（推荐入口） |
| `bin\serve.cmd` / `bin/serve.sh` | 等价的一键启动脚本 |
| `php bin/build.php` | 只用 PHP 编译样式 + 生成扩展清单（无 Node） |
| `php bin/manifest.php --check` | 校验扩展清单（homepage / 引用文件） |
| `npm run build` | 完整构建：样式 + dist 打包 + 清单 + public 同步 |
| `php bin/lolilota doctor` | 后端环境与配置自检 |
| `php bin/lolilota cdifit:probe <邮箱> <密码>` | 真实调用一次 Cdifit 登录 |
| `npm test` / `tests/smoke.ps1` | 后端接口冒烟测试（25 项） |
| `node tests/extension-check.cjs` | 扩展包完整性校验（27 项） |
| `node tests/less-equivalence.cjs` | 校验 PHP 编译器与 Node/less 输出等价 |

---

## 2. 目录结构

```
Lolilota/
├── manifest.json            扩展清单源文件（路径以 dist 为基准，不含真实地址）
├── build.cjs                Node 构建脚本（Less 编译 + dist 打包）
├── package.json
│
├── src/                     ← 前端源码（唯一改动入口）
│   ├── less/                Less 样式源码
│   │   ├── style.less         入口，只做 @import
│   │   ├── _variables.less    设计令牌：颜色 / 圆角 / 阴影 / 动效
│   │   ├── _mixins.less       可复用混入：毛玻璃、渐变、单行省略…
│   │   ├── _base.less         重置 + 页面骨架
│   │   ├── _animations.less   关键帧
│   │   ├── _dark.less         深色主题
│   │   ├── _responsive.less   响应式 + 工具类
│   │   └── components/        组件样式（品牌 / 搜索 / 快捷按钮 / 历史 / 账号 / 组件库覆盖）
│   ├── js/                  前端脚本（按顺序加载，全部挂在 window.Lolilota 下）
│   │   ├── util.js            工具与学生存储封装
│   │   ├── config.js          运行时配置（后端地址、模式、提供方）
│   │   ├── api.js             API 客户端（统一 ok/data、error/code 约定）
│   │   ├── history.js         搜索历史（本地优先）
│   │   ├── sync.js            云端同步（合并策略、防抖推送）
│   │   ├── auth.js            认证状态机（对接 Cdifit 四个接口）
│   │   ├── account.js         账号 UI（登录 / 两步验证 / 同步开关）
│   │   └── script.js          起始页主逻辑（搜索、建议、历史面板、导出）
│   ├── html/newtab.html     页面模板（占位符由构建替换）
│   ├── lib/                 第三方库（JCuPupw 弹窗 / 提示组件）
│   └── icons/               扩展图标
│
├── app/                     ← PHP 后端代码（不在 web 根目录，不可被下载）
│   ├── Core/                  Config / Router / JsonStore / SessionManager / Security
│   ├── Auth/                  Auth 门面 + CdifitProvider + LocalProvider
│   ├── Less/                  纯 PHP 的 Less 编译器（无 Node 也能编译样式）
│   │   ├── Compiler.php        变量 / 嵌套 / 混入 / @import / @media
│   │   ├── Parser.php          语法解析
│   │   ├── Output.php          CSS 输出与花括号配平
│   │   └── Builder.php         构建与开发模式缓存
│   ├── Repository/            UserRepository / HistoryRepository
│   ├── Controller/            AuthController / HistoryController
│   └── Support/               Http / Json / HttpClient / Log / Arr / polyfill / helpers
│
├── config/config.php        后端配置（全部可用环境变量覆盖）
├── public/                  ← web 根目录（构建产物会同步到这里）
│   ├── index.php              页面入口（注入运行时配置）
│   ├── api.php                REST 接口入口
│   ├── router.php             路由 + 开发模式样式实时编译
│   ├── bootstrap.php          引导：常量、自动加载、配置、会话
│   └── css|js|lib|icons/      由构建同步的前端资源
│
├── bin/
│   ├── serve.php            启动脚本（PHP 内置服务器，推荐入口）
│   ├── serve.cmd            Windows 一键启动（可双击）
│   ├── serve.ps1            Windows PowerShell 启动
│   ├── serve.sh             Linux / macOS 启动
│   ├── build.php            纯 PHP 构建：样式 + 扩展清单（不需要 Node.js）
│   ├── manifest.php         扩展清单生成与校验（homepage / 引用文件）
│   └── lolilota             CLI：建号、重置密码、清理会话、自检、Cdifit 联调
│
├── tests/                   接口冒烟测试 + 样式等价性回归 + Cdifit 模拟服务
├── var/                     运行时数据（会话、账号、历史、日志、样式缓存）；需可写
├── css/lolilota.css         样式产物（已提交，站点与扩展共用）
└── dist/                    扩展完整目录（已提交，加载即可用，无需构建）
```

> 构建只会同步 `css/ js/ lib/ icons/` 与页面模板到 `public/`，
> PHP 源码（`index.php` / `api.php` / `router.php` / `bootstrap.php`）不在同步清单里，
> 不会被构建过程清理掉。

---

## 3. 不依赖 Node.js 也能构建与运行

这套项目有**两条独立的构建路径**，任何一条都能把样式编译出来：

| | 需要 Node | 覆盖范围 |
| --- | --- | --- |
| `php bin/build.php` | 不需要 | 编译 Less → `css/lolilota.css` + `dist/css/lolilota.css` |
| `npm run build` | 需要 | 上面的样式 + 扩展目录打包 + HTML/manifest 占位符替换 + public 同步 |

**为什么浏览器扩展不需要编译**：`dist/` 与 `css/lolilota.css` 是**提交进仓库的产物**，
clone 下来直接「加载已解压的扩展」就能用。只有当你改了 `src/` 才需要重新生成。

### 3.1 PHP 版 Less 编译器

`app/Less/` 是一个为这个项目写的极简 Less 实现，精确覆盖源码用到的特性：

- 变量、`@import`（含 `(reference)` 修饰）
- 嵌套规则与 `&` 父选择器引用
- 带参数/默认值的混入（`.glass(@alpha: .82)`）
- `@media` / `@supports`（含嵌套）、`@keyframes`
- 简单算式（`480px * 3`）
- 注释、颜色与数值原样输出

刻意不支持 `each()` / `extract()` / `@{}` 插值 / `~"…"` / 分离规则集 —— 源码里也没用；
遇到无法处理的语法会**报错并给出行号**，而不是静默产出错样式。

正确性由回归测试保证：`php tests/less-equivalence.php` 会把 PHP 编译结果与
Node/less 编译结果都解析成规则集合逐条比对，当前 189 条规则全部一致。

### 3.2 开发模式：改 .less 刷新即见

```bash
php bin/serve.php --dev
```

开发模式下对 `/css/lolilota.css` 的请求会触发**按需编译**：
用源文件指纹（所有 `.less` 的 md5）判断是否需要重编，结果只写 `var/cache/`，
**不会覆盖** `css/` 与 `dist/css/` 里已发布的产物。改完、还原、切分支都能正确识别。

也可以用监听模式，编译产物直接落盘：

```bash
php bin/build.php --watch
```

### 3.3 启动脚本

| 平台 | 命令 |
| --- | --- |
| 任意平台 | `php bin/serve.php [地址] [端口] [--dev]` |
| Windows（CMD，可双击） | `bin\serve.cmd [端口] [dev] [lan]` |
| Windows（PowerShell） | `powershell -ExecutionPolicy Bypass -File bin\serve.ps1 [-Port 8080] [-Dev] [-Lan]` |
| Linux / macOS | `./bin/serve.sh [端口] [dev] [lan]` |

启动脚本会做的事：定位 PHP（含常见安装路径兜底）→ 首次运行时用 PHP 编译样式 →
打印访问地址与扩展加载方式 → 启动服务。

> Windows 上建议用 `.cmd`：它会用 `where php` 找 PHP 并打印友好提示。
> 局域网访问加 `lan`（监听 `0.0.0.0`）。

---

## 4. 样式：为什么用 Less、怎么改

所有颜色与尺寸集中在 `src/less/_variables.less`，改主题只动这一个文件：

```less
@brand-700: #5a7ba8;   // 主色
@radius-pill: 999px;   // 胶囊圆角
@shadow-btn: 0 6px 14px rgba(120, 155, 210, .4), inset 0 -3px 0 rgba(60, 90, 140, .25);
```

组件样式里直接复用变量与混入，不再出现魔法数字：

```less
.pill-btn.blue {
    color: @brand-700;
    background: @brand-100;
    box-shadow: @shadow-pill;
}

.home-container {
    .glass();                 // 毛玻璃
    border-radius: @radius-3xl;
}
```

约定：

- 文件名以下划线开头的是**局部文件**，只给入口 `@import`，不会被单独编译；
- 深色主题统一写在 `_dark.less` 的 `@media (prefers-color-scheme: dark)` 里；
- 构建产物是 `css/lolilota.css` 与 `dist/css/lolilota.css`，**不要直接改**。

---

## 5. 后端接口

统一响应形状（`src/js/api.js` 依赖这个约定）：

```json
{ "ok": true,  "data": { } }
{ "ok": false, "error": { "code": "invalid_credentials", "message": "账号或密码不正确" } }
```

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| GET | `/api/v1/health` | 健康检查（含 PHP 版本、存储、Cdifit 配置状态） |
| GET | `/api/v1/config` | 前端配置：可用登录方式、历史上限 |
| GET | `/api/v1/session` | 当前登录状态 |
| POST | `/api/v1/session/password_sign_in` | 密码登录（可能要求两步验证） |
| POST | `/api/v1/session/finish_sign_in_with_2fa` | 完成两步验证 |
| POST | `/api/v1/session/refresh_token` | 刷新会话（令牌轮换 + 防重放） |
| POST | `/api/v1/session/sign_out` | 退出登录 |
| GET | `/api/v1/history` | 拉取历史（`limit` / `offset`） |
| POST | `/api/v1/history` | 批量推送历史（去重合并） |
| DELETE | `/api/v1/history` | 清空历史 |
| DELETE | `/api/v1/history/{id}` | 删除单条 |

前端只跟 Lolilota 自己的 `/api/v1/session/*` 打交道；Cdifit 的令牌保存在服务端，
不会下发到浏览器或扩展。前端拿到的 `access_token` / `refresh_token` 都是
Lolilota 自己签发的，因此换账号体系不影响前端代码。

**身份识别支持两条通道**，可同时工作：

- 网页：HttpOnly Cookie（同源部署，浏览器自动携带）；
- 扩展：`Authorization: Bearer <access_token>`（跨源，由扩展保存）。

存储默认是 **JSON 文件**（`var/store/*.json`，带文件锁与原子写入），
不依赖任何数据库扩展，普通虚拟主机即可跑。会话令牌只落盘散列值，
文件泄露也无法直接登录。

---

## 6. 接入 Cdifit 账号系统

### 6.1 先配置服务器地址（重要）

仓库里**不包含任何真实服务器地址**：`config/config.php` 与 `manifest.json` 只放占位值，
所有跟部署相关的地址都在下面的本地配置里填写。

```bash
# 1. 复制模板
cp config/local.example.php config/local.php      # Windows: copy config\local.example.php config\local.php

# 2. 填写你自己的地址
```

```php
// config/local.php
return [
    'app' => [
        // 默认就是官方站点 https://lolilota.520world.top/
        // 自建部署时改成自己的域名
        'homepage' => 'https://lolilota.520world.top/',
    ],
    'cdifit' => [
        'base_url' => 'https://你的-cdifit-地址', // Cdifit / Cloudreve 服务地址
    ],
];
```

各地址的默认值：

| 配置项 | 默认值 | 说明 |
| --- | --- | --- |
| `app.homepage` | `https://lolilota.520world.top/` | 站点首页 / 扩展主页（官方站点） |
| `cdifit.base_url` | 空 | 必须自己填；不填登录会提示 `provider_not_configured` |

`config/local.php` 已被 `.gitignore` 忽略，不会被提交。
也可以用环境变量（优先级更高）：`APP_HOMEPAGE`、`CDIFIT_BASE_URL`。

> 官方域名作为产品默认值写在 `config/config.php` 里（`extension-check` 的白名单会放行它）；
> **你自己的服务器地址**必须放 `config/local.php` 或环境变量，不要写进随仓库走的文件。

填完后重新生成扩展清单（homepage 会读进 `dist/manifest.json`）：

```bash
php bin/manifest.php
```

### 6.2 Cdifit 接口约定

Cdifit 是 **Cloudreve** 的接口封装，有两点和常见 REST 服务不一样，适配层已经按它实现：

| 特点 | 说明 |
| --- | --- |
| 路由前缀 | `/api/v4/` |
| HTTP 状态码 | **固定 200**，成功与否由 JSON 里的 `code` 决定 |

```json
// 成功
{ "data": { ... }, "code": 0, "msg": "" }

// 失败
{ "code": 40020, "msg": "Invalid Credentials", "error": "...", "correlation_id": "..." }

// 需要两步验证（密码登录特有）
{ "code": 203, "data": "1728791b-4e6a-4ac5-adf5-fa717a6b0919", "msg": "" }
```

所以判断成败只看 `code`：`0` 成功、`203` 需要 2FA、其余是错误码。
错误码与中文提示的对照表在 [app/Auth/CdifitProvider.php](app/Auth/CdifitProvider.php) 的 `ERROR_MESSAGES`。

### 6.3 实际使用的四个会话接口

| 用途 | 方法与路径 |
| --- | --- |
| 密码登录 | `POST /api/v4/session/token` |
| 完成 2FA | `POST /api/v4/session/token/2fa` |
| 刷新令牌 | `PUT /api/v4/session/token/refresh` |
| 退出登录 | `DELETE /api/v4/session/token` |
| 当前用户（可选兜底） | `GET /api/v4/user/profile` |

请求字段：登录 `{ email, password, captcha?, ticket? }`；
2FA `{ session, code }`，其中 `session` 就是登录响应里 `code=203` 时的 `data`；
刷新 `{ refresh_token }`，**并且刷新令牌要放在 `Authorization: Bearer <RefreshToken>` 头里**。

响应字段：`data.user.{id,email,nickname,avatar,…}` 与
`data.token.{access_token,refresh_token,access_expires,refresh_expires}`；
刷新接口返回的 `data` 直接就是新的令牌集合。

> 关于头像：文档里 `avatar` 常是类型标识（如 `"file"`）而不是 URL，
> 适配层只在它看起来像链接时才当作头像地址，否则前端显示首字母头像。

### 6.4 配置方法（不用改代码）

地址已经填在 `config/local.php`（见 6.1），这里补充其它可调项。
也可以全部改用环境变量（复制 `.env.example` 为 `.env`）：

```bash
APP_HOMEPAGE=https://lolilota.520world.top/   # 站点首页 / 扩展主页（默认即此值）
CDIFIT_BASE_URL=                             # 你的 Cdifit / Cloudreve 服务地址（必填）
CDIFIT_API_PREFIX=/api/v4
CDIFIT_SESSION_PREFIX=/session
CDIFIT_USER_PREFIX=/user
CDIFIT_AUTH_TYPE=none      # 登录/刷新用请求自身凭据，无需服务令牌
```

字段名与路由都能改，`config/config.php` 的 `cdifit.request_fields` /
`cdifit.response_fields` 是「候选键列表」，按顺序尝试取值：

```php
'endpoints' => [
    'password_sign_in'        => '/token',
    'finish_sign_in_with_2fa' => '/token/2fa',
    'refresh_token'           => '/token/refresh',
    'sign_out'                => '/token',
    'user_profile'            => '/profile',   // 走 /user 分组
],
```

### 6.5 联调与排查

先确认环境，再用真实账号打一次接口：

```bash
php bin/lolilota doctor                              # 查看拼出来的完整 URL
php bin/lolilota cdifit:probe <邮箱> <密码>           # 真实调用一次并打印结果
```

`cdifit:probe` 会自动打开追踪，把请求与响应结构写进 `var/logs/cdifit.log`：

```
[时间] CDIFIT.REQUEST  {"endpoint":"password_sign_in","method":"POST","url":".../api/v4/session/token","body":{"email":"...","password":"***(13)"}}
[时间] CDIFIT.RESPONSE {"endpoint":"password_sign_in","status":200,"body":{"code":0,"data":{"user":{...},"token":"***"},"msg":""}}
```

- 密码、令牌、验证码会被打码；
- **数值型的 `code` 保留可见**，因为它是排查的关键；
- 想看页面侧的完整流程，把 `CDIFIT_TRACE=true` 写进环境变量，然后在页面上登录一次。

### 6.6 本地兜底账号

后端连不上 Cdifit 时，可以用本地账号跑通全流程：

```bash
php bin/lolilota create-user alice "Passw0rd!2024" alice@example.com
php bin/lolilota list
php bin/lolilota reset-password alice "NewPassw0rd!2024"
php bin/lolilota delete-user alice
php bin/lolilota gc        # 清理过期会话
```

登录时选择「本地账号」提供方即可（`AUTH_PROVIDERS=local` 可只保留本地账号）。

### 6.7 联调用的模拟服务

仓库自带一个严格按文档实现的 Cdifit 模拟服务，改完后端不用等真实服务就能自测：

```bash
php -S 127.0.0.1:8790 tests/mock/cdifit-router.php

# 另开一个终端
CDIFIT_BASE_URL=http://127.0.0.1:8790 php -S 127.0.0.1:8788 -t public public/router.php
```

测试账号（密码统一 `Passw0rd!2024`）：

| 邮箱 | 行为 |
| --- | --- |
| `user@example.com` | 直接登录成功 |
| `mfa@example.com` | 返回 203，验证码 `123456` 可通过 |
| `banned@example.com` | 返回 40017 账号被封禁 |
| `bad@example.com` | 返回 40020 邮箱或密码错误 |

---

## 7. 部署

### 7.1 PHP 内置服务器（开发）

```bash
php bin/serve.php            # http://127.0.0.1:8787
php bin/serve.php --dev      # 开发模式：改 .less 刷新即见
php bin/serve.php 0.0.0.0 8080   # 指定地址与端口（局域网可访问）

# 等价的平台脚本
bin\serve.cmd                # Windows，可双击
./bin/serve.sh 8080 dev      # Linux / macOS
```

生产环境可以用同一个入口配合进程管理器（如 systemd）跑，但更推荐用 Nginx + PHP-FPM。

### 7.2 Nginx

```nginx
server {
    listen 80;
    server_name lolilota.example.com;
    root /path/to/Lolilota/public;      # 注意：指向 public，不是仓库根目录

    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include        fastcgi_params;
        fastcgi_param  SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass   unix:/run/php/php8.2-fpm.sock;
    }

    # 运行时数据不在 web 根目录，这里只是双保险
    location ~ ^/(var|app|config)/ { deny all; }
}
```

### 7.3 Apache

```apache
DocumentRoot "/path/to/Lolilota/public"

<Directory "/path/to/Lolilota/public">
    AllowOverride None
    Require all granted
    FallbackResource /index.php
</Directory>
```

### 7.4 部署检查清单

- [ ] `var/`（或其父目录）对 PHP 进程可写：`php bin/lolilota doctor`
- [ ] `APP_DEBUG=false`，且不要对外暴露 `app/`、`config/`、`var/`
- [ ] 生产环境用 HTTPS，并在 `php.ini` 打开 `openssl`（Cdifit 走 https 必需）
- [ ] 多机部署时设置同一个 `APP_SECRET`，否则各机会话不互通
- [ ] 需要跨域（扩展指向另一台后端）时设置 `APP_ORIGINS`

### 7.5 扩展形态怎么找到后端

扩展页面读取后端地址的优先级（**默认不写死任何地址**）：

1. 地址栏 `?api=<你的后端地址>`（会记忆下来）
2. `localStorage` / `chrome.storage` 里的 `lolilota_api_base`
3. 页面注入的 `window.__LOLILOTA__`（PHP 站点自动注入，同源部署时为空）
4. 空值 = **同源**：直接请求当前页面所在的站点（站点形态下的默认行为）

扩展内的**账号面板 → 后端服务 → 修改服务地址**即可指定后端，改完可点「测试连接」；
把输入框清空则回到同源模式。`manifest.json` 只声明了本机与 Bing 的必需权限，
指向其他域名时浏览器会提示授予该域的可选权限。

扩展自带的主页地址（`chrome_settings_overrides.homepage`）默认是官方站点
`https://lolilota.520world.top/`，来自 `config/config.php` 的 `app.homepage`；
执行 `php bin/manifest.php` 后写入 `dist/manifest.json`。
自建部署可用 `config/local.php` 或环境变量 `APP_HOMEPAGE` 覆盖，
设为空串则完全不声明该字段（不影响新标签页接管）。

---

## 8. 常见问题

**加载扩展报 `Invalid value for overriding homepage url`**
`chrome_settings_overrides.homepage` 必须是**绝对 URL**，写成相对路径（如
`dist/newtab.html`）Chrome 会直接拒绝加载整个扩展。新标签页接管应该由
`chrome_url_overrides.newtab` 负责（它接受相对路径）。

现在的做法：该值默认取官方站点，由 `php bin/manifest.php` 写入
`dist/manifest.json`；不是绝对 URL 会直接报错终止；设为空串则不声明该字段。
需要改主页就改 `config/local.php` 的 `app.homepage`（或 `APP_HOMEPAGE`），
然后跑一次 `php bin/manifest.php`。

**地址写在哪？会不会被提交？**
随仓库走的文件里只有**官方域名**这一个产品默认值，没有你自建服务器的地址。
你自己的地址写在这两处之一，都不会进仓库：

| 位置 | 是否提交 | 说明 |
| --- | --- | --- |
| `config/local.php` | 否（已 gitignore） | 推荐；`app.homepage`、`cdifit.base_url` |
| `.env` | 否（已 gitignore） | 环境变量方式，优先级更高 |

从模板开始：`cp config/local.example.php config/local.php`。
`node tests/extension-check.cjs` 会扫描构建来源文件，夹带真实地址会直接报错。

**加载扩展后新标签页没变化**
确认加载的是 `dist/` 目录，并且 `dist/manifest.json` 里
`chrome_url_overrides.newtab` 指向的 `newtab.html` 确实存在。
跑一次 `node tests/extension-check.cjs` 可以一次性查出所有路径问题。

**机器上没有 Node.js，能用吗？**
能，而且功能完整：

```bash
php bin/serve.php            # 启动服务
php bin/serve.php --dev      # 开发模式，改 .less 刷新即见
php bin/build.php            # 重新生成样式 + 扩展清单
```

浏览器扩展直接加载 `dist/` 即可（`dist/` 已随仓库提交）。
只有改动了 `src/html/` 模板时才需要 Node 做完整打包。

**`npm install` 报缓存 EPERM / 权限错误**
npm 全局缓存在工作区之外时可能被系统策略拦住，加一个本地缓存目录即可：

```bash
npm install --cache .npm-cache
```

**构建时报文件无法删除 / 写入被拒**
构建脚本对删除失败是宽容的（会改为覆盖写入并继续）。
如果 `css/lolilota.css` 或旧文件确实被占用，关掉占用它的编辑器后重新构建。

**`spawn EPERM`**
这是受限环境禁止创建子进程管道导致的。项目已经**不依赖任何会 spawn 的构建工具**
（不再使用 gulp-cli），`node build.cjs` 与 `php bin/build.php` 都在单进程内完成。

**改了 `.less` 但页面没变化**
- 生产模式：需要重新编译，执行 `php bin/build.php`（或 `npm run build`）。
- 开发模式：用 `php bin/serve.php --dev` 启动，会按源文件指纹自动重编；
  若仍无变化，检查浏览器是否缓存了 CSS（强制刷新 Ctrl+F5）。

**PHP 编译器报「不支持的语法」**
`app/Less/` 只覆盖项目用到的 Less 子集（见 3.1）。如果新增了 `each()`、`@{插值}`、
`~"…"` 这类写法，用 Node 构建即可（`npm run build`）；
或者把该处改写成显式选择器，让两条构建路径都能编译。

**没有 curl / openssl / mbstring**
后端能自动降级：无 curl 用流封装器，无 mbstring 用内置垫片。
但**访问 https 的 Cdifit 服务必须有 openssl**，否则登录会返回明确提示；
`php bin/lolilota doctor` 会直接告诉你缺什么。

**登录提示 `provider_not_configured`**
`CDIFIT_BASE_URL` 没配或不是合法 URL。

**登录提示 `unexpected_response`**
响应结构与映射不匹配。先跑 `php bin/lolilota cdifit:probe <邮箱> <密码>`，
再看 `var/logs/cdifit.log` 里的真实结构（`code` 会保留可见），
按需调整 `config/config.php` 的映射。

**登录一直提示邮箱或密码错误，但密码是对的**
Cdifit 把 HTTP 状态固定为 200，错误都在 `code` 里。常见码：
`40020` 凭据错误、`40021` 用户不存在、`40018` 账号未激活、`40017` 账号被封禁。
如果是 `40026` / `40027`，说明该站点开启了图形验证码，需要补 `captcha` + `ticket`
（本项目已预留字段，但还没做验证码 UI）。

**两步验证码输入后仍报错**
确认用的是「验证器 App 里的 TOTP 码」而不是邮箱验证码；错误码 `40022` 即验证码错误，
`40023` 表示 2FA 会话已过期，重新走一次密码登录即可。

**日志里的中文是乱码？**
本项目已改用 UTF-8 直接写文件（不用 PHP 的 `error_log()`，它会按系统
ANSI 代码页落盘，中文 Windows 上是 GBK）。日志文件：`var/logs/app.log`。
用支持 UTF-8 的编辑器打开即可；`type` 命令看到的乱码是控制台代码页问题。

---

## 9. 数据与隐私

- 搜索历史**默认只存在浏览器本地**（`localStorage`），登录并开启同步后才上传；
- 云端历史按账号隔离，存在 `var/store/history.json`；
- 会话令牌只保存 SHA-256 散列，`var/store/sessions.json` 泄露也无法直接登录；
- 登录口令由 Cdifit 校验，本地不保存明文密码；本地账号使用
  `password_hash`（argon2id / bcrypt），环境不支持时回落到内置 PBKDF2-SHA256；
- 关闭同步后，本机历史保留，云端记录不再改动。

## 许可证

MIT
