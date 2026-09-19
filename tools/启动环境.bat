@echo off
chcp 65001 >nul
title Dxcx 环境启动器
setlocal EnableDelayedExpansion

set "ROOT=E:\Dxcx"
set "MYSQLD=C:\Users\Administrator\AppData\Roaming\Local\lightning-services\mysql-8.0.35+4\bin\win64\bin\mysqld.exe"

echo ============================================================
echo   Dxcx 本地环境一键启动  (MySQL / Redis / ThinkPHP / 双前端)
echo ============================================================
echo.

REM ---------- MySQL 3306 ----------
call :check 3306
if "!FREE!"=="0" (
    echo [跳过] MySQL 3306 已在运行
) else (
    echo [启动] MySQL 3306 ...
    start "MySQL" /MIN cmd /c ""%MYSQLD%" --defaults-file="%ROOT%\tools\mysql\my.ini""
    timeout /t 3 /nobreak >nul
)

REM ---------- Redis 6379 ----------
call :check 6379
if "!FREE!"=="0" (
    echo [跳过] Redis 6379 已在运行
) else (
    echo [启动] Redis 6379 ...
    start "Redis" /MIN cmd /c "cd /d %ROOT%\tools\redis && redis-server.exe redis.windows.conf"
    timeout /t 2 /nobreak >nul
)

REM ---------- ThinkPHP 8080 ----------
call :check 8080
if "!FREE!"=="0" (
    echo [跳过] ThinkPHP 8080 已在运行
) else (
    echo [启动] ThinkPHP 后端 8080 ...
    start "ThinkPHP" /MIN cmd /c "cd /d %ROOT% && php think run -p 8080"
    timeout /t 3 /nobreak >nul
)

REM ---------- console-admin 5173 ----------
call :check 5173
if "!FREE!"=="0" (
    echo [跳过] 管理后台 5173 已在运行
) else (
    echo [启动] 管理后台 console-admin 5173 ...
    start "console-admin" cmd /k "cd /d %ROOT%\frontend\console-admin && npm run dev"
)

REM ---------- uniapp-client 5174 ----------
call :check 5174
if "!FREE!"=="0" (
    echo [跳过] H5 前端 5174 已在运行
) else (
    echo [启动] H5 前端 uniapp-client 5174 ...
    start "uniapp-client" cmd /k "cd /d %ROOT%\frontend\uniapp-client && npm run dev:h5"
)

echo.
echo ------------------------------------------------------------
echo  全部已处理，前端首次编译约需 10~20 秒，请耐心等待窗口出现
echo  "ready" / "Local:" 字样后再访问：
echo.
echo    后端接口 / 官网 : http://127.0.0.1:8080/
echo    管理后台       : http://127.0.0.1:5173/#/Merchant/index
echo    H5 预览        : http://127.0.0.1:5174/
echo    （前端用 localhost 或 127.0.0.1 打开均可，已做 IPv4/IPv6 双栈）
echo ------------------------------------------------------------
echo.
echo 15 秒后自动打开管理后台；若页面空白说明仍在编译，刷新即可。
timeout /t 15 /nobreak >nul
start "" "http://127.0.0.1:5173/#/Merchant/index"
echo.
echo 本窗口可以关闭；各服务运行在独立窗口中，关闭对应窗口即停该服务，
echo 或双击「停止环境.bat」一次性全部停止。
pause
exit /b

REM ===== 端口检测子程序：FREE=1 表示端口空闲需要启动，FREE=0 表示已占用 =====
:check
set "FREE=1"
for /f "delims=" %%i in ('netstat -ano -p tcp ^| findstr "LISTENING" ^| findstr ":%~1 "') do set "FREE=0"
exit /b
