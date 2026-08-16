@echo off
cd /d C:\Users\user\Desktop\AutnyxV2
git add -A >nul 2>&1
git diff --cached --quiet >nul 2>&1
if %ERRORLEVEL% NEQ 0 (
    for /f "tokens=1-3 delims=/ " %%a in ("%date%") do set d=%%c-%%b-%%a
    for /f "tokens=1-2 delims=: " %%a in ("%time%") do set t=%%a:%%b
    git commit -m "Auto-push: %d% %t%" >nul 2>&1
    git push -u origin main >>C:\Users\user\Desktop\AutnyxV2\.watcher.log 2>&1
    echo [%date% %time%] Pushed OK >> C:\Users\user\Desktop\AutnyxV2\.watcher.log
)
