@echo off
chcp 65001 >nul
title SICA - Configurar cliente (Valentina)
cd /d "%~dp0\.."

echo ============================================
echo   SICA - PC CLIENTE (conectar al servidor)
echo ============================================
echo.

if exist "config\database.local.php" (
    echo Ya existe config\database.local.php
    type config\database.local.php
    echo.
    pause
    exit /b 0
)

copy /Y "config\database.local.example.php" "config\database.local.php" >nul
echo Se creo config\database.local.php
echo.
echo IMPORTANTE: Abre ese archivo y cambia la IP en host
echo por la IP del PC de Kevin (la que el te pase).
echo.
echo Ejemplo: 'host' =^> '192.168.10.89',
echo.
notepad config\database.local.php
pause
