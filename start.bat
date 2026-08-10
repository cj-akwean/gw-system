@echo off
title GW-System Launcher
cd /d "%~dp0"

if not exist backend\vendor (
    echo Backend dependencies missing. Run setup.bat first.
    pause
    exit /b 1
)

start "GW Backend + Queue" cmd /k "cd /d %~dp0backend && composer dev"
start "GW Frontend" cmd /k "cd /d %~dp0frontend && npm run dev"

echo.
echo GW-System services starting...
echo   Portal  http://localhost:3000
echo   API     http://127.0.0.1:8000
echo   Admin   http://127.0.0.1:8000/admin
echo.
echo Close the two GW windows to stop the services.
echo.
