$taskName = "AutnyxV2 Auto-Push"
$vbsFile  = "C:\Users\user\Desktop\AutnyxV2\autopush.vbs"

Unregister-ScheduledTask -TaskName $taskName -Confirm:$false -ErrorAction SilentlyContinue

$action   = New-ScheduledTaskAction -Execute "wscript.exe" -Argument "//B //NoLogo `"$vbsFile`""
$trigger  = New-ScheduledTaskTrigger -RepetitionInterval (New-TimeSpan -Minutes 1) -Once -At (Get-Date)
$settings = New-ScheduledTaskSettingsSet -ExecutionTimeLimit (New-TimeSpan -Minutes 1) -StartWhenAvailable

Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Settings $settings -RunLevel Highest -Force | Out-Null

Write-Host ""
Write-Host "Done! Auto-push now runs silently every minute - no more CMD flashes." -ForegroundColor Green
