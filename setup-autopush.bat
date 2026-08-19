@echo off
cd /d "%~dp0"
echo ============================================================
echo   Autnyx auto-push setup (silent / no flashing window)
echo ============================================================
echo.
echo This re-registers the every-minute auto-push task so it runs
echo hidden via autopush-hidden.vbs (no CMD window will pop up).
echo.

echo [1/2] Registering scheduled task "AutnyxAutoPush" (hidden, every minute)...
schtasks /Create /TN "AutnyxAutoPush" /TR "wscript.exe //B //Nologo \"%~dp0autopush-hidden.vbs\"" /SC MINUTE /MO 1 /F
if errorlevel 1 (
  echo.
  echo   Could not register the task. Right-click this file and choose
  echo   "Run as administrator", then run it again.
  echo.
  pause
  exit /b 1
)

echo.
echo [2/2] Running one push now to ship pending changes...
call "%~dp0autopush.bat"

echo.
echo ============================================================
echo   Done. Auto-push runs every minute, silently.
echo   No more flashing window.
echo ============================================================
echo.
echo   Verify anytime with:  schtasks /Query /TN AutnyxAutoPush
echo.
pause
