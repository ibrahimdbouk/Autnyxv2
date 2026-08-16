$taskName = "AutnyxV2 Auto-Push"
$batFile  = "C:\Users\user\Desktop\AutnyxV2\autopush.bat"

Unregister-ScheduledTask -TaskName $taskName -Confirm:$false -ErrorAction SilentlyContinue

$action   = New-ScheduledTaskAction -Execute "cmd.exe" -Argument "/c `"$batFile`""
$trigger  = New-ScheduledTaskTrigger -RepetitionInterval (New-TimeSpan -Minutes 1) -Once -At (Get-Date)
$settings = New-ScheduledTaskSettingsSet -ExecutionTimeLimit (New-TimeSpan -Minutes 1) -StartWhenAvailable

Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Settings $settings -RunLevel Highest -Force | Out-Null

Write-Host ""
Write-Host "Done! autopush.bat will run silently every minute." -ForegroundColor Green
Write-Host "Check .watcher.log to see push history." -ForegroundColor Cyan
