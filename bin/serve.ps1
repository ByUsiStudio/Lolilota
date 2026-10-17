<#
.SYNOPSIS
  Lolilota 启动脚本（Windows / PowerShell）

.DESCRIPTION
  用 PHP 内置服务器启动 Lolilota，不依赖 Node.js。

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File bin\serve.ps1
  powershell -ExecutionPolicy Bypass -File bin\serve.ps1 -Port 8080
  powershell -ExecutionPolicy Bypass -File bin\serve.ps1 -Dev
  powershell -ExecutionPolicy Bypass -File bin\serve.ps1 -Lan
#>
[CmdletBinding()]
param(
    [string]$HostName = '127.0.0.1',
    [int]$Port = 8787,
    [switch]$Dev,
    [switch]$Lan,
    [switch]$NoBuild
)

$ErrorActionPreference = 'Stop'

# 以脚本位置推导项目根目录，保证在任意工作目录下都能运行
$Root = Split-Path -Parent $PSScriptRoot
if (-not (Test-Path (Join-Path $Root 'public\router.php'))) {
    Write-Host "找不到 public\router.php，请确认脚本位于项目的 bin\ 目录下" -ForegroundColor Red
    exit 1
}

# ---- 找 PHP ------------------------------------------------------------
$php = $null
foreach ($candidate in @('php', 'php.exe')) {
    $cmd = Get-Command $candidate -ErrorAction SilentlyContinue
    if ($cmd) { $php = $cmd.Source; break }
}

if (-not $php) {
    # 常见安装位置兜底
    foreach ($candidate in @(
        "$env:ProgramFiles\PHP\current\php.exe",
        "$env:ProgramFiles\PHP\php.exe",
        'C:\php\php.exe',
        'D:\php\php.exe'
    )) {
        if (Test-Path $candidate) { $php = $candidate; break }
    }
}

if (-not $php) {
    Write-Host "没有找到 php 可执行文件。" -ForegroundColor Red
    Write-Host "请安装 PHP 8.1+ 并把它加入 PATH： https://www.php.net/downloads" -ForegroundColor Yellow
    exit 1
}

if ($Lan) { $HostName = '0.0.0.0' }

# ---- 首次运行：编译样式 -------------------------------------------------
$source = Join-Path $Root 'src\less\style.less'
$output = Join-Path $Root 'css\lolilota.css'

if (-not $NoBuild -and $Dev) {
    Write-Host "开发模式：样式将在请求时实时编译（无需 Node.js）" -ForegroundColor DarkGray
} elseif (-not $NoBuild -and -not (Test-Path $output) -and (Test-Path $source)) {
    Write-Host "首次运行：用 PHP 编译 Less 样式…" -ForegroundColor Cyan
    & $php (Join-Path $Root 'bin\build.php') --no-minify | Out-Null
    if ($LASTEXITCODE -ne 0) {
        Write-Host "样式编译失败，可稍后手动执行：php bin\build.php" -ForegroundColor Yellow
    }
}

# ---- 环境变量与启动 ----------------------------------------------------
$env:LOLILOTA_DEV = if ($Dev) { '1' } else { '0' }

$displayHost = if ($HostName -eq '0.0.0.0') { '127.0.0.1' } else { $HostName }
$url = "http://${displayHost}:$Port"

$documentRoot = Join-Path $Root 'public'
$router = Join-Path $documentRoot 'router.php'

Write-Host ""
Write-Host "  Lolilota 已启动" -ForegroundColor Green
Write-Host "  ------------------------------------------------------------"
Write-Host "  访问地址    $url"
Write-Host "  站点根目录  $documentRoot"
Write-Host "  运行模式    $(if ($Dev) { '开发（样式实时编译）' } else { '生产（使用编译产物）' })"
Write-Host ""
Write-Host "  浏览器扩展  加载仓库根目录（读取 dist/）"
Write-Host "  停止服务    Ctrl+C"
Write-Host ""

& $php -S "${HostName}:${Port}" -t $documentRoot $router
exit $LASTEXITCODE
