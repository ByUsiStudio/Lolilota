@echo off
rem ============================================================================
rem  Lolilota launcher for Windows (double-clickable)
rem
rem  Usage:
rem    serve.cmd            start http://127.0.0.1:8787
rem    serve.cmd 8080       custom port
rem    serve.cmd dev        development mode (Less recompiled on request)
rem    serve.cmd lan        listen on 0.0.0.0
rem
rem  This file is intentionally ASCII-only and WITHOUT a BOM:
rem  cmd.exe parses .cmd files using the local code page, and a UTF-8 BOM
rem  corrupts the first line. All localized messages are printed by
rem  serve.ps1, which is UTF-8 with a BOM (needed by Windows PowerShell 5.1).
rem
rem  No Node.js required.
rem ============================================================================
setlocal EnableDelayedExpansion

set "ROOT=%~dp0.."

rem ---- arguments -----------------------------------------------------------
set "PORT=8787"
set "MODE="

:parse
if "%~1"=="" goto parsed
if /i "%~1"=="dev" (
    set "MODE=-Dev"
    shift
    goto parse
)
if /i "%~1"=="lan" (
    set "MODE=-Lan"
    shift
    goto parse
)
echo %~1| findstr /r "^[0-9][0-9]*$" >nul
if not errorlevel 1 (
    set "PORT=%~1"
    shift
    goto parse
)
echo Unknown argument: %~1
echo Usage: serve.cmd [port] [dev] [lan]
pause
exit /b 1
:parsed

rem ---- locate PHP ---------------------------------------------------------
set "PHP="
where php >nul 2>nul && set "PHP=php"
if not defined PHP if exist "%ProgramFiles%\PHP\current\php.exe" set "PHP=%ProgramFiles%\PHP\current\php.exe"
if not defined PHP if exist "%ProgramFiles%\PHP\php.exe" set "PHP=%ProgramFiles%\PHP\php.exe"
if not defined PHP if exist "C:\php\php.exe" set "PHP=C:\php\php.exe"

if not defined PHP (
    echo.
    echo   PHP not found. Please install PHP 8.1+ and add it to PATH:
    echo   https://www.php.net/downloads
    echo.
    pause
    exit /b 1
)

rem ---- hand over to the PowerShell launcher (localized output + checks) ----
powershell -NoProfile -ExecutionPolicy Bypass -File "%ROOT%\bin\serve.ps1" -Port %PORT% %MODE%
set "CODE=%ERRORLEVEL%"

if not "%CODE%"=="0" (
    echo.
    echo   Launcher exited with code %CODE%.
    pause
)

endlocal
exit /b %CODE%
