# Registers the auto-push watcher as a Windows Task Scheduler task
# It will launch silently every time you log in

$taskName   = "AutnyxV2 Auto-Push Watcher"
$scriptDir  = Split-Path -Parent $MyInvocation.MyCommand.Definition
$watcherScript = Join-Path $scriptDir "watcher.ps1"

# Remove existing task if it exists
Unregister-ScheduledTask -TaskName $taskName -Confirm:$false -ErrorAction SilentlyContinue

$action = New-ScheduledTaskAction `
    -Execute "powershell.exe" `
    -Argument "-NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File `"$watcherScript`""

$trigger = New-ScheduledTaskTrigger -AtLogOn

$settings = New-ScheduledTaskSettingsSet `
    -ExecutionTimeLimit (New-TimeSpan -Hours 0) `
    -RestartCount 3 `
    -RestartInterval (New-TimeSpan -Minutes 1) `
    -StartWhenAvailable

Register-ScheduledTask `
    -TaskName $taskName `
    -Action $action `
    -Trigger $trigger `
    -Settings $settings `
    -RunLevel Highest `
    -Force | Out-Null

# Start it right now too (no need to log out/in)
Start-ScheduledTask -TaskName $taskName

Write-Host ""
Write-Host "✅ Startup task registered: '$taskName'" -ForegroundColor Green
Write-Host "   The watcher is now running AND will auto-start every time you log in." -ForegroundColor Cyan
Write-Host ""
Write-Host "   To check status:  Get-ScheduledTask -TaskName '$taskName'" -ForegroundColor Gray
Write-Host "   To remove it:     Unregister-ScheduledTask -TaskName '$taskName' -Confirm:`$false" -ForegroundColor Gray
Write-Host ""
