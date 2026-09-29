@echo off
REM Cross-platform launcher (Windows). Linux users: run ./serve.sh instead.
REM All logic lives in serve.php so both OSes behave identically.
setlocal
set "DIR=%~dp0"
php "%DIR%serve.php" %*
endlocal
