@echo off
chcp 65001 >nul
title Dxcx 环境停止器
setlocal EnableDelayedExpansion
echo ============================================================
echo   停止 Dxcx 全部本地服务 (3306 / 6379 / 8080 / 5173 / 5174)
echo ============================================================
echo.

for %%P in (3306 6379 8080 5173 5174) do (
    set "FOUND=0"
    for /f "tokens=5" %%a in ('netstat -ano -p tcp ^| findstr "LISTENING" ^| findstr ":%%P "') do (
        echo 停止端口 %%P  ->  PID %%a 及其子进程
        taskkill /PID %%a /T /F >nul 2>nul
        set "FOUND=1"
    )
    if "!FOUND!"=="0" echo 端口 %%P 未在运行
)

REM 兜底：按进程名清理本项目专用数据库/缓存（前端 node、PHP 已由端口进程树覆盖，避免误伤其它程序）
taskkill /IM mysqld.exe /T /F >nul 2>nul
taskkill /IM redis-server.exe /T /F >nul 2>nul

echo.
echo ------------------------------------------------------------
echo  停止后端口状态：
for %%P in (3306 6379 8080 5173 5174) do (
    set "ST=已停止"
    for /f "delims=" %%i in ('netstat -ano -p tcp ^| findstr "LISTENING" ^| findstr ":%%P "') do set "ST=仍在运行!!"
    echo    %%P  !ST!
)
echo ------------------------------------------------------------
echo.
pause
