# Run this ONCE to initialize git and connect to GitHub
Set-Location -Path $PSScriptRoot

git init
git remote add origin https://ibrahimdbouk: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@github.com/ibrahimdbouk/Autnyxv2.git
git config user.name "Ibrahim Dbouk"
git config user.email "ibrahim.dbouk@gmail.com"
git branch -M main

Write-Host ""
Write-Host "✅ Git initialized and connected to GitHub!" -ForegroundColor Green
Write-Host "   Run push.ps1 any time you want to push changes." -ForegroundColor Cyan
