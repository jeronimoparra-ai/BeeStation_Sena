@echo off
REM ============================================================
REM  iniciar-beestation.bat - Arranca XAMPP y abre BeeStation
REM  Equivalente para Windows de scripts/iniciar-beestation.sh
REM ============================================================
setlocal enabledelayedexpansion

set "XAMPP=C:\xampp"
set "RUTA=%XAMPP%\htdocs\BeeStation_Sena"

REM --- 1. Apache y MySQL ---
if not exist "%XAMPP%\apache_start.bat" (
  echo [ERROR] No se encontro XAMPP en %XAMPP%
  pause
  exit /b 1
)

tasklist /FI "IMAGENAME eq httpd.exe" 2>NUL | find /I "httpd.exe" >NUL
if errorlevel 1 (
  echo [1/3] Iniciando Apache...
  start "" "%XAMPP%\apache_start.bat"
) else (
  echo [1/3] Apache ya esta corriendo.
)

tasklist /FI "IMAGENAME eq mysqld.exe" 2>NUL | find /I "mysqld.exe" >NUL
if errorlevel 1 (
  echo [2/3] Iniciando MySQL...
  start "" "%XAMPP%\mysql_start.bat"
) else (
  echo [2/3] MySQL ya esta corriendo.
)

REM --- Espera a que el puerto 80 responda ---
echo       Esperando a que el puerto 80 levante...
set /a intentos=0
:espera
timeout /t 1 /nobreak >NUL 2>&1
powershell -NoProfile -Command "if (Test-NetConnection -ComputerName 127.0.0.1 -Port 80 -InformationLevel Quiet -WarningAction SilentlyContinue) { exit 0 } else { exit 1 }" >NUL 2>&1
if not errorlevel 1 goto listo
set /a intentos+=1
if %intentos% lss 30 goto espera
echo [ERROR] El puerto 80 no respondio. Revisa XAMPP.
pause
exit /b 1

:listo
echo [3/3] Abriendo BeeStation...
REM --- IP LAN de este equipo (para el simulador del otro PC) ---
set "IP="
for /f "tokens=2 delims=:" %%a in ('ipconfig ^| findstr /c:"IPv4"') do (
  for /f "tokens=* delims= " %%b in ("%%a") do set "IP=%%b"
)
if defined IP (
  echo.
  echo       URL local    : http://localhost/BeeStation_Sena/
  echo       URL en la LAN: http://%IP%/BeeStation_Sena/
  echo       Entrega esta URL al PC del simulador.
  echo.
) else (
  echo       URL local    : http://localhost/BeeStation_Sena/
)

start "" "http://localhost/BeeStation_Sena/login.php"
endlocal
