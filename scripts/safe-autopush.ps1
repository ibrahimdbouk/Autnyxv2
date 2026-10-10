<#
  Safe auto-push for Autnyx V2 (replaces the old every-minute AutnyxAutoPush).
  Scheduled every 30 min by setup-safe-autopush.ps1.

  - One run at a time (named mutex + task setting IgnoreNew)
  - Cheap check first: does nothing at all if the repo is clean and in sync
  - Commits + pushes only when files actually changed
  - Pulls phone/cloud changes (fast-forward) when the local copy is clean
  - Never deletes git lock files unless they are stale (>15 min)
  - Log: scripts\safe-autopush.log (gitignored)
#>
$ErrorActionPreference = 'Continue'
$repo = Split-Path -Parent $PSScriptRoot
$log  = Join-Path $PSScriptRoot 'safe-autopush.log'
function Log($m) { Add-Content -Path $log -Value ("{0:yyyy-MM-dd HH:mm:ss} {1}" -f (Get-Date), $m) }

$mutex = New-Object System.Threading.Mutex($false, 'Local\AutnyxSafePush')
if (-not $mutex.WaitOne(0)) { exit 0 }   # another run is still going
try {
    Set-Location $repo

    # Only clear a lock file if it is clearly abandoned
    foreach ($l in '.git\index.lock', '.git\HEAD.lock') {
        if ((Test-Path $l) -and (Get-Item $l).LastWriteTime -lt (Get-Date).AddMinutes(-15)) {
            Remove-Item -Force $l; Log "Removed stale $l"
        }
    }

    $changes = git status --porcelain
    git fetch -q origin main 2>$null
    $ahead  = [int](git rev-list --count origin/main..HEAD 2>$null)
    $behind = [int](git rev-list --count HEAD..origin/main 2>$null)

    if (-not $changes -and $ahead -eq 0 -and $behind -eq 0) { exit 0 }   # nothing to do

    if ($changes) {
        git add -A
        $n = @($changes).Count
        git -c user.email="ibrahim.dbouk@gmail.com" -c user.name="Ibrahim" commit -q -m "auto: sync $n file(s) from PC"
        Log "Committed $n changed file(s)"
    }

    if ($behind -gt 0) {
        git pull -q --rebase origin main
        if ($LASTEXITCODE -ne 0) {
            git rebase --abort 2>$null
            Log "CONFLICT pulling phone/cloud changes - resolve manually (git pull --rebase origin main)"
            exit 1
        }
        Log "Pulled $behind commit(s) from GitHub"
    }

    $ahead = [int](git rev-list --count origin/main..HEAD 2>$null)
    if ($ahead -gt 0) {
        git push -q origin main
        if ($LASTEXITCODE -eq 0) { Log "Pushed $ahead commit(s) -> Laravel Cloud deploy" }
        else { Log "Push failed (exit $LASTEXITCODE) - will retry next run" }
    }
}
finally { $mutex.ReleaseMutex(); $mutex.Dispose() }
