@echo off
cd /d "C:\Users\user\Desktop\AutnyxV2"

echo Clearing git locks...
if exist .git\index.lock del /f .git\index.lock
if exist .git\config.lock del /f .git\config.lock
if exist .git\config.lock.bak del /f .git\config.lock.bak

echo Committing and pushing...
git config user.email "ibrahim.dbouk@gmail.com"
git config user.name "Ibrahim"
git add -A
git commit -m "Fix: investigate page 500, dashboard action class, P3/P4/P5 complete"
git push origin main

echo Done! Check Laravel Cloud for new deployment.
pause
