@echo off
title GW-System Uninstall
echo ============================================
echo   GW-System - full uninstall
echo ============================================
echo.
echo   Removes databases, generated files, installed tools,
echo   and (optionally) the whole project folder.
echo.
echo   NOTE: if you plan to delete the project folder itself,
echo   run this from OUTSIDE it, e.g.:
echo     cd C:\Users\%USERNAME%
echo     D:\gw-system\uninstall.bat
echo.
echo   Note: setup edits PHP's php.ini (memory_limit, extensions, SSL CA).
echo   That is only removed if you also uninstall PHP (stage 3).
echo.
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0uninstall.ps1" %*
echo.
pause
