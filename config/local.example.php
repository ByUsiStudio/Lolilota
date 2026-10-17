<?php
/**
 * Lolilota · 本地部署配置（模板）
 *
 * 复制为 config/local.php 后填写你自己的地址：
 *
 *     cp config/local.example.php config/local.php
 *
 * 为什么单独放一个文件：
 *   config/config.php 会随仓库一起提交，里面**不能出现任何真实服务器地址**。
 *   所有与具体部署相关的值都写在这里，而 config/local.php 已被 .gitignore 忽略。
 *
 * 本文件返回的数组会与 config/config.php 递归合并（同名键取这里的值），
 * 因此只需要写要覆盖的部分，其余保持默认。
 *
 * 注意：环境变量优先级更高，设置了 CDIFIT_BASE_URL 等就不必再写这里。
 */

declare(strict_types=1);

return [
    'app' => [
        // 站点首页 / 扩展主页（必须是绝对 URL）
        //
        // 用途：
        //   1. 扩展的 chrome_settings_overrides.homepage
        //   2. 页面注入给前端的 window.__LOLILOTA__.homepage
        //
        // 留空则扩展不声明 homepage —— 不影响新标签页接管（那是
        // chrome_url_overrides.newtab 的职责）。
        'homepage' => '',   // 例如 'https://你的域名/'

        // 允许跨域访问后端的前端来源（同源部署留空）
        // 扩展形态会自动放行 chrome-extension:// 与 moz-extension://。
        'origins' => [],    // 例如 ['https://你的前端域名']
    ],

    'cdifit' => [
        // 你自己部署的 Cdifit / Cloudreve 站点根地址
        // 留空则登录返回 provider_not_configured，提示尚未配置。
        'base_url' => '',   // 例如 'https://你的-auth-地址'
    ],
];
