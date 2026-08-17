@echo off
cd /d C:\Users\user\Desktop\AutnyxV2

rem Clear any stale git lock files before starting
if exist .git\index.lock del /f .git\index.lock >nul 2>&1
if exist .git\HEAD.lock del /f .git\HEAD.lock >nul 2>&1

git add -A >nul 2>&1
git rm --cached .watcher.log >nul 2>&1
git restore --staged test-push.txt >nul 2>&1
git diff --cached --quiet >nul 2>&1
if %ERRORLEVEL% NEQ 0 (
    git commit -m "Auto-push: %date% %time:~0,5%" >nul 2>&1
    git push -u origin main >> C:\Users\user\Desktop\AutnyxV2\.watcher.log 2>&1 && (
        echo [%date% %time:~0,8%] Pushed OK >> C:\Users\user\Desktop\AutnyxV2\.watcher.log
    ) || (
        echo [%date% %time:~0,8%] Push FAILED - check above >> C:\Users\user\Desktop\AutnyxV2\.watcher.log
    )
)
