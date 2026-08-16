# Autnyx V2 - Auto Git Push Watcher
param()

$projectPath = "C:\Users\user\Desktop\AutnyxV2"
$logFile     = Join-Path $projectPath ".watcher.log"
$debounceMs  = 4000
$pollMs      = 2000

$ignoreList  = @('.git', '.watcher.log', 'watcher.ps1', 'start-watcher.ps1',
                 'setup-git.ps1', 'setup-startup.ps1', 'push.ps1')

function Write-Log {
    param([string]$msg)
    $entry = "[" + (Get-Date -Format 'yyyy-MM-dd HH:mm:ss') + "] " + $msg
    Add-Content -Path $logFile -Value $entry -Encoding UTF8
}

function Test-Ignored {
    param([string]$fullPath)
    foreach ($item in $ignoreList) {
        if ($fullPath -like ("*" + $item + "*")) { return $true }
    }
    return $false
}

# Find git.exe explicitly
$gitCmd = (Get-Command git -ErrorAction SilentlyContinue).Source
if (-not $gitCmd) {
    $candidates = @(
        "C:\Program Files\Git\bin\git.exe",
        "C:\Program Files (x86)\Git\bin\git.exe"
    )
    foreach ($c in $candidates) {
        if (Test-Path $c) { $gitCmd = $c; break }
    }
}
if (-not $gitCmd) {
    Write-Log "ERROR: git.exe not found. Install Git for Windows."
    exit 1
}

Set-Location $projectPath

# Init git if needed
$remoteOut = & $gitCmd remote -v 2>&1
if ($remoteOut -notmatch "origin") {
    & $gitCmd init
    & $gitCmd remote add origin "https://ibrahimdbouk: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@github.com/ibrahimdbouk/Autnyxv2.git"
    & $gitCmd config user.name "Ibrahim Dbouk"
    & $gitCmd config user.email "ibrahim.dbouk@gmail.com"
    & $gitCmd branch -M main
    Write-Log "Git initialised and remote set."
}

Write-Log "Watcher started. Monitoring: $projectPath"

$lastPushTime = Get-Date
$pendingSince = $null

while ($true) {
    Start-Sleep -Milliseconds $pollMs

    $changed = @(Get-ChildItem -Path $projectPath -Recurse -File -ErrorAction SilentlyContinue |
        Where-Object { $_.LastWriteTime -gt $lastPushTime -and -not (Test-Ignored $_.FullName) })

    if ($changed.Count -gt 0) {
        if ($null -eq $pendingSince) { $pendingSince = Get-Date }

        $elapsed = ((Get-Date) - $pendingSince).TotalMilliseconds
        if ($elapsed -ge $debounceMs) {
            Set-Location $projectPath

            $statusOut = & $gitCmd status --porcelain 2>&1
            if ($statusOut) {
                $ts = Get-Date -Format 'yyyy-MM-dd HH:mm'
                & $gitCmd add -A 2>&1 | Out-Null
                & $gitCmd commit -m "Auto-push: $ts" 2>&1 | Out-Null
                $pushOut = & $gitCmd push -u origin main 2>&1
                $pushStr = $pushOut -join ' '
                if ($LASTEXITCODE -eq 0) {
                    $names = ($changed | Select-Object -ExpandProperty Name) -join ', '
                    Write-Log ("Pushed OK. Files: " + $names)
                } else {
                    Write-Log ("Push FAILED: " + $pushStr)
                }
            }

            $lastPushTime = Get-Date
            $pendingSince = $null
        }
    } else {
        $pendingSince = $null
    }
}
