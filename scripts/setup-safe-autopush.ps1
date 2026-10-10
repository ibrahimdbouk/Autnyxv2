<#
  Registers the safe auto-push task (every 30 min, hidden, one run at a time)
  and removes the two old every-minute tasks.

  Run once in an ADMIN PowerShell from the AutnyxV2 folder:
    powershell -ExecutionPolicy Bypass -File scripts\setup-safe-autopush.ps1
  Undo:
    powershell -ExecutionPolicy Bypass -File scripts\setup-safe-autopush.ps1 -Remove
#>
param([switch]$Remove)
$name   = 'AutnyxSafePush'
$script = Join-Path $PSScriptRoot 'safe-autopush.ps1'

if ($Remove) {
    Unregister-ScheduledTask -TaskName $name -Confirm:$false -ErrorAction SilentlyContinue
    Write-Host "Removed $name." -ForegroundColor Yellow; exit 0
}

# Old every-minute tasks -> gone for good
foreach ($old in 'AutnyxAutoPush', 'AutnyxV2 Auto-Push', 'AutnyxShipWatch') {
    Unregister-ScheduledTask -TaskName $old -Confirm:$false -ErrorAction SilentlyContinue
}

# conhost --headless = no window flash, no VBScript needed
$action   = New-ScheduledTaskAction -Execute 'conhost.exe' `
            -Argument "--headless powershell.exe -NoProfile -ExecutionPolicy Bypass -File `"$script`""
$trigger  = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(2) `
            -RepetitionInterval (New-TimeSpan -Minutes 30)
$settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew `
            -ExecutionTimeLimit (New-TimeSpan -Minutes 10) -StartWhenAvailable `
            -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
Register-ScheduledTask -TaskName $name -Action $action -Trigger $trigger -Settings $settings `
            -User $env:USERNAME -RunLevel Limited -Force | Out-Null

Write-Host "$name is on: every 30 min, hidden, one run at a time, only when something changed." -ForegroundColor Green
Write-Host "Old every-minute tasks removed. Log: scripts\safe-autopush.log"
