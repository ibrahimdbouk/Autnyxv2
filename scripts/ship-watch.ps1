<#
  W9 / W0 - atomic, named shipping (replaces the every-minute "auto: sync changes").

  Claude delivers a change as a BATCH folder under _ship\ (gitignored):

      _ship\<batch-id>\files\<repo path>      the new content of each file
      _ship\<batch-id>\manifest.json          written LAST:
          { "message": "WP9.7: ...",            the commit message (with its trailers)
            "files":   [ { "path": "app/X.php", "sha256": "..." } ],
            "delete":  [ "app/Old.php" ] }

  Every minute this script (scheduled by ship-setup.ps1) takes each complete
  batch in name order: checks every file's SHA-256 against the manifest (a
  half-copied batch waits), copies the files into the repo, deletes the
  listed paths, commits ONLY those paths with the batch's message and
  pushes. One batch = one named commit = one CI run - never a half-shipped
  change. A batch that fails is moved to _ship\_failed with a reason; a
  shipped one to _ship\_done.

  Your own edits are not touched: only the batch's paths are committed.
  To commit your own work, use scripts\ship.ps1 (or git as usual).
#>
param([string]$Repo = (Split-Path -Parent $PSScriptRoot))
$ErrorActionPreference = 'Continue'   # native tools write to stderr; exit codes are checked instead
$inbox = Join-Path $Repo '_ship'
$log   = Join-Path $inbox 'ship.log'
function Log($m) { Add-Content -Path $log -Value ("{0:u} {1}" -f (Get-Date), $m) }
function Sha($f) { (Get-FileHash -Algorithm SHA256 -LiteralPath $f).Hash.ToLower() }

if (-not (Test-Path $inbox)) { exit 0 }
$lock = Join-Path $inbox '.lock'
if (Test-Path $lock) {
    if ((Get-Item $lock).LastWriteTime -gt (Get-Date).AddMinutes(-15)) { exit 0 }   # another run is shipping
}
Set-Content -Path $lock -Value $PID
try {
    Set-Location $Repo
    foreach ($name in '.git\index.lock', '.git\HEAD.lock') { if (Test-Path $name) { Remove-Item -Force $name } }
    New-Item -ItemType Directory -Force -Path (Join-Path $inbox '_done'), (Join-Path $inbox '_failed') | Out-Null

    $batches = Get-ChildItem -Path $inbox -Directory | Where-Object { $_.Name -notlike '_*' } | Sort-Object Name
    foreach ($b in $batches) {
        $manifestPath = Join-Path $b.FullName 'manifest.json'
        if (-not (Test-Path $manifestPath)) { continue }                       # still arriving
        $m = Get-Content -Raw -Encoding UTF8 -LiteralPath $manifestPath -ErrorAction Stop | ConvertFrom-Json -ErrorAction Stop
        $files = @($m.files); $deletes = @($m.delete | Where-Object { $_ })

        # Complete? Every file present with the announced hash.
        $missing = @()
        foreach ($f in $files) {
            $src = Join-Path (Join-Path $b.FullName 'files') $f.path
            if (-not (Test-Path -LiteralPath $src) -or (Sha $src) -ne $f.sha256.ToLower()) { $missing += $f.path }
        }
        if ($missing) {
            if ($b.LastWriteTime -lt (Get-Date).AddMinutes(-30)) {
                Log "FAILED $($b.Name): incomplete after 30 min: $($missing -join ', ')"
                Move-Item -LiteralPath $b.FullName -Destination (Join-Path $inbox "_failed\$($b.Name)") -Force
            }
            continue
        }

        $paths = @()
        foreach ($f in $files) {
            $src = Join-Path (Join-Path $b.FullName 'files') $f.path
            $dst = Join-Path $Repo $f.path
            New-Item -ItemType Directory -Force -Path (Split-Path -Parent $dst) | Out-Null
            Copy-Item -LiteralPath $src -Destination $dst -Force -ErrorAction Stop
            $paths += $f.path
        }
        foreach ($d in $deletes) {
            $dst = Join-Path $Repo $d
            if (Test-Path -LiteralPath $dst) { Remove-Item -LiteralPath $dst -Force }
            $paths += $d
        }

        git add -A -- $paths
        git diff --cached --quiet
        if ($LASTEXITCODE -eq 0) {
            Log "SKIPPED $($b.Name): no change (already shipped?)"
            Move-Item -LiteralPath $b.FullName -Destination (Join-Path $inbox "_done\$($b.Name)") -Force
            continue
        }
        $msgFile = Join-Path $b.FullName 'message.txt'
        [IO.File]::WriteAllText($msgFile, [string]$m.message, (New-Object Text.UTF8Encoding $false))
        git -c user.email="ibrahim.dbouk@gmail.com" -c user.name="Ibrahim" commit -q -F $msgFile -- $paths
        if ($LASTEXITCODE -ne 0) { throw "commit failed for $($b.Name)" }
        $sha = (git rev-parse --short HEAD).Trim()

        git push -q origin main 2>$null
        if ($LASTEXITCODE -ne 0) {
            git pull -q --rebase origin main
            git push -q origin main
        }
        if ($LASTEXITCODE -ne 0) {
            Log "COMMITTED $($b.Name) as $sha but the push failed - it will go out with the next push."
        } else {
            Log "SHIPPED $($b.Name) as $sha ($($paths.Count) path(s))"
        }
        Move-Item -LiteralPath $b.FullName -Destination (Join-Path $inbox "_done\$($b.Name)") -Force
    }

    # Keep two weeks of shipped batches.
    Get-ChildItem -Path (Join-Path $inbox '_done') -Directory | Where-Object { $_.LastWriteTime -lt (Get-Date).AddDays(-14) } |
        Remove-Item -Recurse -Force
}
catch {
    Log "ERROR $($_.Exception.Message)"
}
finally {
    Remove-Item -Force $lock -ErrorAction SilentlyContinue
}
