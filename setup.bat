@echo off
title GW-System Setup

:: Relaunch as administrator if we are not already elevated
:: (installs machine-wide software and writes to Program Files)
net session >nul 2>&1
if %errorlevel% neq 0 (
    echo Requesting administrator privileges...
    echo If a User Account Control (UAC) prompt appears, click "Yes".
    powershell -NoProfile -Command "Start-Process -FilePath '%~f0' -Verb RunAs"
    exit /b
)

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
