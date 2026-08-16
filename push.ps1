# Run this any time you want to push changes to GitHub
Set-Location -Path $PSScriptRoot

$msg = Read-Host "Commit message (or press Enter for 'Update')"
if ([string]::IsNullOrWhiteSpace($msg)) { $msg = "Update" }

git add -A
git commit -m $msg
git push -u origin main

Write-Host ""
Write-Host "✅ Pushed to GitHub!" -ForegroundColor Green
