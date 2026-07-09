@echo off
chcp 65001 >nul
title SICA - Configurar MySQL servidor (Kevin)
cd /d "%~dp0\.."

echo ============================================
echo   SICA - PC SERVIDOR (base compartida)
echo ============================================
echo.

set MYSQL=C:\xampp\mysql\bin\mysql.exe
if not exist "%MYSQL%" (
    echo No se encontro MySQL en C:\xampp
    pause
    exit /b 1
)

echo 1. Creando usuario remoto sica_equipo ...
"%MYSQL%" -u root < database\setup_usuario_remoto.sql
if errorlevel 1 (
    echo Error creando usuario. ¿MySQL esta encendido en XAMPP?
    pause
    exit /b 1
)

echo 2. Abriendo puerto 3306 en el firewall ...
netsh advfirewall firewall add rule name="MySQL SICA equipo" dir=in action=allow protocol=TCP localport=3306 >nul 2>&1

echo.
echo 3. Tu IP en la red (pasasela a Valentina):
for /f "tokens=2 delims=:" %%a in ('ipconfig ^| findstr /c:"IPv4"') do echo    %%a
echo.
echo 4. Valentina debe copiar:
echo    config\database.local.example.php
echo    como
echo    config\database.local.php
echo    y poner TU IP en host.
echo.
echo 5. Manten XAMPP encendido (Apache + MySQL) cuando trabajen juntas.
echo    Misma red WiFi.
echo.
pause
