#!/usr/bin/env bash
#
# Lolilota 启动脚本（Linux / macOS）
#
# 用法：
#   ./bin/serve.sh                 启动 http://127.0.0.1:8787
#   ./bin/serve.sh 8080            指定端口
#   ./bin/serve.sh dev             开发模式（样式实时编译）
#   ./bin/serve.sh lan             监听 0.0.0.0，局域网可访问
#
# 不依赖 Node.js，只用 PHP 内置服务器。

set -euo pipefail

# 以脚本位置推导项目根目录
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

if [ ! -f "public/router.php" ]; then
    echo "找不到 public/router.php，请确认脚本位于项目的 bin/ 目录下" >&2
    exit 1
fi

# ---- 找 PHP ----------------------------------------------------------------
if ! command -v php >/dev/null 2>&1; then
    echo "没有找到 php 可执行文件。" >&2
    echo "请安装 PHP 8.1+： https://www.php.net/downloads" >&2
    exit 1
fi

# ---- 参数 ------------------------------------------------------------------
HOST="127.0.0.1"
PORT="8787"
DEV="0"

for arg in "$@"; do
    case "$arg" in
        dev)  DEV="1" ;;
        lan)  HOST="0.0.0.0" ;;
        [0-9]*) PORT="$arg" ;;
        -h|--help)
            sed -n '3,12p' "$0" | sed 's/^# \{0,1\}//'
            exit 0
            ;;
        *)
            echo "未知参数：$arg" >&2
            echo "用法：serve.sh [端口] [dev] [lan]" >&2
            exit 1
            ;;
    esac
done

# ---- 首次运行：编译样式 ----------------------------------------------------
if [ "$DEV" = "0" ] && [ ! -f "css/lolilota.css" ] && [ -f "src/less/style.less" ]; then
    echo "首次运行：用 PHP 编译 Less 样式…"
    php bin/build.php --no-minify || echo "样式编译失败，可稍后执行：php bin/build.php"
fi

export LOLILOTA_DEV="$DEV"

DISPLAY_HOST="$HOST"
[ "$HOST" = "0.0.0.0" ] && DISPLAY_HOST="127.0.0.1"

cat <<TXT

  Lolilota 已启动
  ------------------------------------------------------------
  访问地址    http://${DISPLAY_HOST}:${PORT}
  站点根目录  ${ROOT}/public
  运行模式    $( [ "$DEV" = "1" ] && echo "开发（样式实时编译）" || echo "生产（使用编译产物）" )

  浏览器扩展  加载仓库根目录（读取 dist/）
  停止服务    Ctrl+C

TXT

exec php -S "${HOST}:${PORT}" -t "$ROOT/public" "$ROOT/public/router.php"
