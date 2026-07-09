@echo off
chcp 65001 >nul
title SICA - Base compartida en la nube
cd /d "%~dp0\.."

echo ============================================
echo   SICA - BASE COMPARTIDA (desde cualquier lugar)
echo ============================================
echo.
echo Esta opcion sirve cuando NO estan en la misma WiFi.
echo Las dos usan la MISMA base en internet.
echo.

if exist "config\database.local.php" (
    echo Ya tienes config\database.local.php:
    type config\database.local.php
    echo.
    choice /C SN /M "Quieres reemplazarlo con la plantilla de nube"
    if errorlevel 2 goto fin
)

copy /Y "config\database.cloud.example.php" "config\database.local.php" >nul
echo.
echo Se creo config\database.local.php
echo.
echo PASOS (hacer UNA sola vez en equipo):
echo   1. Entrar a https://www.db4free.net y crear cuenta gratis
echo   2. Crear base de datos (nombre: sica)
echo   3. En phpMyAdmin de db4free: Importar - sica_equipo.sql
echo   4. Editar database.local.php con usuario, clave y host de db4free
echo   5. Enviar el MISMO database.local.php a la companera por WhatsApp
echo      (o escribir los mismos datos en su PC)
echo.
echo Probar con: php tools\probar_conexion.php
echo.
notepad config\database.local.php

:fin
pause
