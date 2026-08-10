<#
GW-System — environment verifier
Read-only. Prints [ OK ] / [MISSING] for every prerequisite. Makes no changes.

Usage:  powershell -NoProfile -ExecutionPolicy Bypass -File verify.ps1
#>
$ErrorActionPreference = "Continue"

function Report {
    param([string]$label, [bool]$ok, [string]$detail = "")
    $mark = if ($ok) { "[  OK  ]" } else { "[MISSING]" }
    $color = if ($ok) { "Green" } else { "Yellow" }
    Write-Host ("{0,-46} {1}  {2}" -f $label, $mark, $detail) -ForegroundColor $color
}

$root    = Split-Path -Parent $MyInvocation.MyCommand.Path
$backend = Join-Path $root "backend"

Write-Host ""
Write-Host "GW-System environment check" -ForegroundColor Cyan
Write-Host ("Machine: {0}  |  {1}" -f $env:COMPUTERNAME, (Get-Date -Format "yyyy-MM-dd HH:mm")) -ForegroundColor DarkGray
Write-Host ("-" * 95)

# --- winget ---------------------------------------------------------------
Report "winget" ([bool](Get-Command winget -ErrorAction SilentlyContinue))

# --- PHP ------------------------------------------------------------------
$php = Get-Command php -ErrorAction SilentlyContinue
$phpVer = if ($php) { (& php -v | Select-Object -First 1) } else { "" }
Report "PHP" ([bool]$php) $phpVer

$iniPath = $null
if ($php) {
    $iniLine = & php --ini 2>$null | Select-String "^Loaded Configuration File"
    if ($iniLine) { $iniPath = ($iniLine.ToString() -split ":", 2)[1].Trim() }
}
Report "php.ini loaded" ([bool]$iniPath) $iniPath

if ($php -and $iniPath) {
    $mem = (& php -r "echo ini_get('memory_limit');")
    Report "memory_limit = 512M" ($mem -eq "512M") $mem

    $mods = @(& php -m 2>$null)
    foreach ($ext in "pdo_pgsql","pgsql","mbstring","fileinfo","intl","zip","curl","openssl") {
        Report "extension: $ext" ([bool]($mods -contains $ext))
    }

    $ca = ((& php -r "echo ini_get('curl.cainfo');" 2>$null) | Select-Object -Last 1).Trim()
    Report "SSL CA bundle" ([bool]$ca -and (Test-Path $ca)) $ca
}

# --- Composer / Node --------------------------------------------------------
$composer = Get-Command composer -ErrorAction SilentlyContinue
Report "Composer" ([bool]$composer) $(if ($composer) { (& composer --version 2>$null | Select-Object -First 1) })
$node = Get-Command node -ErrorAction SilentlyContinue
Report "Node.js" ([bool]$node) $(if ($node) { (& node -v 2>$null) })

# --- PostgreSQL -------------------------------------------------------------
$psql = Get-Command psql -ErrorAction SilentlyContinue
Report "psql on PATH" ([bool]$psql) $(if ($psql) { $psql.Source })
$svc = Get-Service -ErrorAction SilentlyContinue | Where-Object { $_.Name -like "*postgresql*" } | Select-Object -First 1
Report "PostgreSQL service" ($svc -and $svc.Status -eq "Running") $(if ($svc) { "$($svc.Name): $($svc.Status)" } else { "not installed" })

if ($psql) {
    $env:PGPASSWORD = "postgres"
    $dbs = @(& psql -U postgres -h 127.0.0.1 -t -A -c "SELECT datname FROM pg_database;" 2>$null)
    Report "DB: gw_system" ([bool]($dbs -contains "gw_system"))
    Report "DB: gw_system_testing" ([bool]($dbs -contains "gw_system_testing"))
    Remove-Item Env:\PGPASSWORD -ErrorAction SilentlyContinue
}

# --- Project ---------------------------------------------------------------
Report "backend/vendor" (Test-Path (Join-Path $backend "vendor"))
Report "backend/.env" (Test-Path (Join-Path $backend ".env"))
$appKey = ""
if (Test-Path (Join-Path $backend ".env")) {
    $appKey = ((Select-String -Path (Join-Path $backend ".env") -Pattern "^APP_KEY=").Line -replace "^APP_KEY=", "")
}
Report "backend APP_KEY set" ([bool]$appKey)
Report "frontend/node_modules" (Test-Path (Join-Path $root "frontend\node_modules"))

Write-Host ("-" * 95)
Write-Host "Done. Missing items: run setup.bat (or: setup.ps1)." -ForegroundColor Cyan
Write-Host ""
