@echo off
chcp 65001 >nul
title Importar base SICA
cd /d "%~dp0"

echo ============================================
echo   IMPORTAR BASE DE DATOS SICA
echo ============================================
echo.

if not exist "sica_equipo.sql" (
    echo ERROR: No encuentro el archivo sica_equipo.sql
    echo.
    echo Debe estar en esta misma carpeta:
    echo %~dp0
    echo.
    pause
    exit /b 1
)

echo 1. Verificando MySQL...
C:\xampp\mysql\bin\mysql.exe -u root -e "SELECT 1;" >nul 2>&1
if errorlevel 1 (
    echo ERROR: MySQL no esta encendido.
    echo Abre XAMPP y dale Start a MySQL.
    echo.
    pause
    exit /b 1
)

echo 2. Creando base sica si no existe...
C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE IF NOT EXISTS sica CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"

echo 3. Importando datos (puede tardar unos segundos)...
C:\xampp\mysql\bin\mysql.exe -u root sica < sica_equipo.sql
if errorlevel 1 (
    echo.
    echo ERROR al importar. Toma captura de este mensaje.
    echo.
    pause
    exit /b 1
)

echo.
echo ============================================
echo   LISTO! Importacion exitosa.
echo ============================================
echo.
echo Ahora:
echo   - XAMPP: Apache Stop y Start
echo   - Navegador: Ctrl + F5
echo   - Login admin: kevinandres212004@gmail.com
echo.
pause
