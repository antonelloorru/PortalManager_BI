<#
  PortalManager — install_v1.7.58.ps1
  Copia i file della release nella root del portale (servita da Apache) e verifica
  che i marker della patch siano effettivamente presenti nei file LIVE.

  USO (PowerShell, anche non-admin):
    1. Estrai lo ZIP dove vuoi (anche Downloads). Assicurati che questo .ps1 sia
       nella stessa cartella dei file php/app/sql della release.
    2. Apri PowerShell in quella cartella ed esegui:
         powershell -ExecutionPolicy Bypass -File .\install_v1.7.58.ps1 -PortalRoot "P:\xampp\htdocs\portalmanager"
       (se ometti -PortalRoot usa P:\xampp\htdocs\portalmanager)

  NB: lo schema DB 1.7.58 risulta già applicato nel tuo ambiente; questo script
      tocca SOLO i file. Fa un backup .bak.1.7.58 di ogni file sovrascritto.
#>
param(
  [string]$PortalRoot = "P:\xampp\htdocs\portalmanager"
)

$ErrorActionPreference = "Stop"
$src = $PSScriptRoot
Write-Host "== PortalManager v1.7.58 — installer ==" -ForegroundColor Cyan
Write-Host "Sorgente : $src"
Write-Host "Portale  : $PortalRoot`n"

if (-not (Test-Path (Join-Path $PortalRoot "manage_employees.php"))) {
  Write-Host "ERRORE: '$PortalRoot' non sembra la root di PortalManager (manca manage_employees.php)." -ForegroundColor Red
  Write-Host "Rilancia con il path corretto: -PortalRoot ""<percorso>""" -ForegroundColor Yellow
  exit 1
}

# file da copiare: relativo -> destinazione sotto PortalRoot
$files = @(
  "manage_employees.php",
  "employee_profile.php",
  "manage_permissions.php",
  "db_upgrade.php",
  "manage_departments.php",
  "app\MenuManager.php",
  "app\Version.php",
  "VERSION",
  "sql\migration_v1_7_58.sql"
)

foreach ($rel in $files) {
  $from = Join-Path $src $rel
  $to   = Join-Path $PortalRoot $rel
  if (-not (Test-Path $from)) { Write-Host "SKIP (assente nel pacchetto): $rel" -ForegroundColor Yellow; continue }
  $destDir = Split-Path $to -Parent
  if (-not (Test-Path $destDir)) { New-Item -ItemType Directory -Force -Path $destDir | Out-Null }
  if (Test-Path $to) {
    $bak = "$to.bak.1.7.58"
    if (-not (Test-Path $bak)) { Copy-Item $to $bak -Force }
  }
  Copy-Item $from $to -Force
  Write-Host "COPIATO  $rel" -ForegroundColor Green
}

Write-Host "`n== Verifica marker nei file LIVE ==" -ForegroundColor Cyan
function Check($path, $pattern, $label) {
  $full = Join-Path $PortalRoot $path
  if (Select-String -Path $full -Pattern $pattern -Quiet) {
    Write-Host "OK   $label" -ForegroundColor Green
  } else {
    Write-Host "FAIL $label  ($path)" -ForegroundColor Red
    $script:failed = $true
  }
}
$script:failed = $false
Check "manage_employees.php" 'name="department_id"'      "select Dipartimento (manage_employees)"
Check "employee_profile.php" 'name="department_id"'      "select Dipartimento (employee_profile)"
Check "app\MenuManager.php"  "manage_departments"          "voce menu"
Check "manage_permissions.php" "manage_departments.php"    "page_map permesso"
Check "VERSION"              "1.7.58"                       "VERSION"
Check "app\Version.php"      "1.7.58"                       "PM_VERSION"

if ($script:failed) {
  Write-Host "`nAlcuni marker mancano: il path del portale potrebbe non essere quello servito da Apache." -ForegroundColor Red
} else {
  Write-Host "`nTutti i marker presenti. Ora: STOP+START di Apache (svuota OPcache) e Ctrl+F5 nel browser." -ForegroundColor Green
  Write-Host "Se sei Super Admin vedrai subito 'Amministrazione -> Dipartimenti / Unita Org.'." -ForegroundColor Green
}
