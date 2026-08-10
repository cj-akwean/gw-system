@echo off
title GW-System Setup
echo ============================================
echo   GW-System - one-time setup
echo ============================================
echo.
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0setup.ps1"
echo.
if %errorlevel% equ 0 (
    echo.
    echo Setup finished. Run start.bat to launch the app.
) else (
    echo.
    echo Setup finished with errors - scroll up for details.
)
echo.
pause
