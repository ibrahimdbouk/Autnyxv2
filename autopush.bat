@echo off
cd /d "C:\Users\user\Desktop\AutnyxV2"

:: Clear any stale git lock files (silent - no error if missing)
if exist .git\index.lock       del /f /q .git\index.lock
if exist .git\HEAD.lock        del /f /q .git\HEAD.lock
if exist .git\config.lock      del /f /q .git\config.lock
if exist .git\config.lock.bak  del /f /q .git\config.lock.bak

:: Stage all changes
git add -A 2>nul

:: Commit only if something is staged
git diff --cached --quiet 2>nul
if %errorlevel% neq 0 (
    git -c user.email="ibrahim.dbouk@gmail.com" -c user.name="Ibrahim" commit -m "auto: sync changes"
)

:: Always push - handles commits made by other means too
git push origin main 2>nul

exit /b 0
