@echo off
cd /d "%~dp0"
echo ============================================================
echo   Autnyx auto-push setup
echo ============================================================
echo.
echo This registers a Windows scheduled task that runs autopush.bat
echo every minute, so changes push to GitHub automatically. It also
echo does one push right now to ship anything pending.
echo.

echo [1/2] Registering scheduled task "AutnyxAutoPush" (every minute)...
schtasks /Create /TN "AutnyxAutoPush" /TR "\"%~dp0autopush.bat\"" /SC MINUTE /MO 1 /F
if errorlevel 1 (
  echo.
  echo   Could not register the task. Right-click this file and choose
  echo   "Run as administrator", then run it again.
  echo.
  pause
  exit /b 1
)

echo.
echo [2/2] Running an initial push now...
call "%~dp0autopush.bat"

echo.
echo ============================================================
echo   Done. Auto-push is active and runs every minute.
echo   You do not need to run fix-and-push.bat anymore.
echo ============================================================
echo.
echo   To confirm it is alive later, open Task Scheduler and look for
echo   "AutnyxAutoPush", or run:  schtasks /Query /TN AutnyxAutoPush
echo.
pause
