# Launches the file watcher silently in the background
# Run this once — it keeps running until you close PowerShell or restart

$scriptDir  = Split-Path -Parent $MyInvocation.MyCommand.Definition
$watcherScript = Join-Path $scriptDir "watcher.ps1"

$process = Start-Process powershell.exe `
    -ArgumentList "-NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File `"$watcherScript`"" `
    -PassThru

Write-Host ""
Write-Host "✅ Auto-push watcher is running in the background (PID: $($process.Id))" -ForegroundColor Green
Write-Host "   Any file changes Claude makes will be auto-committed & pushed to GitHub." -ForegroundColor Cyan
Write-Host "   Log: C:\Users\user\Desktop\AutnyxV2\.watcher.log" -ForegroundColor Gray
Write-Host ""
Write-Host "   To stop the watcher, run:" -ForegroundColor Yellow
Write-Host "   Stop-Process -Id $($process.Id)" -ForegroundColor Yellow
Write-Host ""
