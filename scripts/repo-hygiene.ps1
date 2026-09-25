<#
  W9 / W0 - repo hygiene (run ONCE on the dev PC, from the repo folder).

  Stops tracking files that never belonged in the repository: generated
  exports (Claude outputs\, ~15 MB of CSVs), git bundles and patches, and
  the personal auto-push scripts. The files stay on your disk - they are
  only removed from the repository (and ignored from now on, see .gitignore).

    powershell -ExecutionPolicy Bypass -File scripts\repo-hygiene.ps1          # shows what it would do
    powershell -ExecutionPolicy Bypass -File scripts\repo-hygiene.ps1 -Apply   # does it, commits, pushes

  Note: this does not rewrite history. Anything ever committed stays in the
  history - that is why a leaked token must be REVOKED, not just deleted.
#>
param([switch]$Apply)
$ErrorActionPreference = 'Continue'   # native tools write to stderr; exit codes are checked instead
$repo = Split-Path -Parent $PSScriptRoot
Set-Location $repo

$paths = @(
    'Claude outputs',
    '_to_delete',
    'autnyx-clickable-widgets.patch',
    'autnyx-full.bundle',
    'autnyx-new-commits.bundle',
    'autopush.bat',
    'autopush-hidden.vbs',
    'fix-and-push.bat',
    'setup-autopush.bat'
)

$tracked = @()
foreach ($p in $paths) {
    $files = git ls-files -- "$p"
    if ($files) { $tracked += $files }
}
if (-not $tracked) { Write-Host 'Nothing to untrack - the repo is already clean.' -ForegroundColor Green; exit 0 }

Write-Host "Tracked files that will be removed from the repository (kept on disk):" -ForegroundColor Cyan
$tracked | ForEach-Object { Write-Host "  $_" }
if (-not $Apply) { Write-Host "`nDry run. Re-run with -Apply to do it." -ForegroundColor Yellow; exit 0 }

# The every-minute auto-push must not race this commit.
schtasks /Change /TN AutnyxAutoPush /DISABLE 2>$null | Out-Null
try {
    foreach ($p in $paths) { git rm -r -q --cached --ignore-unmatch -- "$p" }
    git add .gitignore
    git -c user.email="ibrahim.dbouk@gmail.com" -c user.name="Ibrahim" commit -q -m "Repo hygiene: stop tracking generated exports, bundles and personal scripts"
    if ($LASTEXITCODE -ne 0) { throw 'The commit failed - nothing was pushed.' }
    git push origin main
    if ($LASTEXITCODE -ne 0) { throw 'The push failed - run "git push origin main" once the network is back.' }
    Write-Host "`nDone. $($tracked.Count) file(s) untracked; they are still on your disk." -ForegroundColor Green
}
finally {
    schtasks /Change /TN AutnyxAutoPush /ENABLE 2>$null | Out-Null
}
