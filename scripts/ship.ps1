<#
  W9 / W0 - commit and push YOUR OWN changes with a real message, once
  ship-watch has replaced auto-push.

    powershell -ExecutionPolicy Bypass -File scripts\ship.ps1 -Message "Fix the store list" [-Paths app\X.php,resources\y.blade.php]

  Without -Paths every change in the repo is committed (like auto-push did).
#>
param([Parameter(Mandatory)][string]$Message, [string[]]$Paths)
$ErrorActionPreference = 'Continue'   # native tools write to stderr; exit codes are checked instead
Set-Location (Split-Path -Parent $PSScriptRoot)
if ($Paths) { git add -A -- $Paths } else { git add -A }
git diff --cached --quiet
if ($LASTEXITCODE -eq 0) { Write-Host 'Nothing to commit.'; exit 0 }
git -c user.email="ibrahim.dbouk@gmail.com" -c user.name="Ibrahim" commit -q -m $Message
git push origin main
if ($LASTEXITCODE -ne 0) { git pull --rebase origin main; git push origin main }
Write-Host ("Shipped " + (git rev-parse --short HEAD)) -ForegroundColor Green
