# tools/safe_backup.ps1 — Script PowerShell per backup pre-aggiornamento sicuro (No Timeout)
[CmdletBinding()]
param(
    [switch]$SkipFiles,
    [switch]$SkipDb
)

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$AppRoot = Split-Path -Parent $ScriptDir
Set-Location $AppRoot

Write-Host "Verifica eseguibile PHP..." -ForegroundColor Cyan

$phpExe = $null
$candidates = @(
    "php",
    "P:\xampp\php\php.exe",
    "C:\xampp\php\php.exe",
    "D:\xampp\php\php.exe"
)

foreach ($c in $candidates) {
    if (Get-Command $c -ErrorAction SilentlyContinue) {
        $phpExe = $c
        break
    } elseif (Test-Path $c) {
        $phpExe = $c
        break
    }
}

if (-not $phpExe) {
    Write-Error "Impossibile trovare PHP. Assicurati che php.exe sia nel PATH o in XAMPP."
    exit 1
}

Write-Host "PHP rilevato: $phpExe" -ForegroundColor Green

$args = @("tools/cli_backup.php")
if ($SkipFiles) { $args += "--skip-files" }
if ($SkipDb)    { $args += "--skip-db" }

& $phpExe @args
