<#
  W9 / W0 - switch from "auto: sync changes every minute" to atomic, named
  shipping (scripts\ship-watch.ps1). Run once, from the repo folder:

    powershell -ExecutionPolicy Bypass -File scripts\ship-setup.ps1            # switch over
    powershell -ExecutionPolicy Bypass -File scripts\ship-setup.ps1 -Revert    # back to auto-push

  After switching, only batches Claude delivers under _ship\ are committed -
  each as one named commit. Your own edits wait for you (scripts\ship.ps1).
#>
param([switch]$Revert)
$ErrorActionPreference = 'Continue'   # native tools write to stderr; exit codes are checked instead
$repo  = Split-Path -Parent $PSScriptRoot
$watch = Join-Path $repo 'scripts\ship-watch.ps1'

if ($Revert) {
    schtasks /Delete /TN AutnyxShipWatch /F 2>$null | Out-Null
    schtasks /Change /TN AutnyxAutoPush /ENABLE | Out-Null
    Write-Host 'Reverted: auto-push is back on, ship-watch removed.' -ForegroundColor Yellow
    exit 0
}

New-Item -ItemType Directory -Force -Path (Join-Path $repo '_ship') | Out-Null
$cmd = "powershell.exe -NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File `"$watch`""
schtasks /Create /TN AutnyxShipWatch /TR $cmd /SC MINUTE /MO 1 /F | Out-Null
if ($LASTEXITCODE -ne 0) { Write-Host 'Could not register the task - run PowerShell as administrator and try again.' -ForegroundColor Red; exit 1 }
schtasks /Change /TN AutnyxAutoPush /DISABLE 2>$null | Out-Null

Write-Host 'Ship-watch is on (every minute, hidden). Auto-push is disabled.' -ForegroundColor Green
Write-Host "Log: $repo\_ship\ship.log     Undo: scripts\ship-setup.ps1 -Revert"
