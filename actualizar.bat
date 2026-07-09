@echo off
chcp 65001 >nul
cd /d "%~dp0"

echo === Actualizar Proyecto SICA desde GitHub ===
echo.

git rev-parse --is-inside-work-tree >nul 2>&1
if errorlevel 1 (
    echo Esta carpeta no es un repositorio git.
    echo Descarga el ZIP desde:
    echo https://github.com/valentinahiguitasuarez-blip/Proyecto_SICA/archive/refs/heads/main.zip
    pause
    exit /b 1
)

git checkout main
if errorlevel 1 (
    echo No se pudo cambiar a la rama main.
    pause
    exit /b 1
)

git pull origin main
if errorlevel 1 (
    echo.
    echo No se pudo actualizar. Si hay conflictos, guarda tus cambios locales primero.
    pause
    exit /b 1
)

echo.
echo Listo. Ultimo commit:
git log -1 --oneline
echo.
echo Reinicia Apache en XAMPP y recarga el navegador con Ctrl+F5.
pause
