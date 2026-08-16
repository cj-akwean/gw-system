<#
GW-System - automated setup
Installs and configures everything needed to run the project on Windows:
PHP 8.5 (+ php.ini, extensions, SSL CA), Composer, Node.js, PostgreSQL 18,
databases, backend dependencies, migrations + seed, frontend dependencies.

Safe to re-run: every step detects what is already installed and skips it.

Usage:  powershell -NoProfile -ExecutionPolicy Bypass -File setup.ps1 [-DbPassword "postgres"]
#>
param([string]$DbPassword = "postgres")

$ErrorActionPreference = "Stop"

$root    = Split-Path -Parent $MyInvocation.MyCommand.Path
$backend = Join-Path $root "backend"
$frontend = Join-Path $root "frontend"

function Write-Step { param([string]$msg) Write-Host ""; Write-Host "==> $msg" -ForegroundColor Cyan }
function Write-OK   { param([string]$msg) Write-Host "    OK  $msg" -ForegroundColor Green }
function Write-Skip { param([string]$msg) Write-Host "    --  $msg (already done, skipping)" -ForegroundColor DarkGray }
function Write-Fail { param([string]$msg) Write-Host "    !!  $msg" -ForegroundColor Yellow }
function Refresh-Path {
    $env:Path = [Environment]::GetEnvironmentVariable("Path", "Machine") + ";" +
                [Environment]::GetEnvironmentVariable("Path", "User")
}
function Add-UserPath {
    param([string]$dir)
    if (-not $dir -or -not (Test-Path $dir)) { return }
    $userPath = [Environment]::GetEnvironmentVariable("Path", "User")
    if ($userPath -notlike "*$dir*") {
        [Environment]::SetEnvironmentVariable("Path", "$userPath;$dir", "User")
    }
    if ($env:Path -notlike "*$dir*") { $env:Path = "$env:Path;$dir" }
}

Write-Host ""
Write-Host "========================================================" -ForegroundColor Cyan
Write-Host "  GW-System setup" -ForegroundColor Cyan
Write-Host "  Machine: $env:COMPUTERNAME  |  $(Get-Date -Format 'yyyy-MM-dd HH:mm')" -ForegroundColor DarkGray
Write-Host "========================================================" -ForegroundColor Cyan

# --- 1. winget ---------------------------------------------------------------
Write-Step "Prerequisite: winget"
if (-not (Get-Command winget -ErrorAction SilentlyContinue)) {
    Write-Fail "winget not found. Install it from the Microsoft Store ('App Installer'), then re-run this script."
    exit 1
}
Write-OK "winget available"

# --- 2. PHP ------------------------------------------------------------------
Write-Step "PHP 8.5"
$php = Get-Command php -ErrorAction SilentlyContinue
if (-not $php) {
    Write-Host "    Installing PHP 8.5 via winget (this can take a minute)..."
    winget install --id PHP.PHP.8.5 --source winget --accept-source-agreements --accept-package-agreements --silent
    Refresh-Path
    $php = Get-Command php -ErrorAction SilentlyContinue
    if (-not $php) { Write-Fail "PHP install finished but php is not on PATH. Open a new terminal and re-run."; exit 1 }
    Write-OK "PHP installed: $($php.Source)"
} else {
    $phpVer = (& php -v | Select-Object -First 1)
    $verMatch = [regex]::Match($phpVer, 'PHP (\d+)\.(\d+)')
    if ($verMatch.Success) {
        $major = [int]$verMatch.Groups[1].Value
        $minor = [int]$verMatch.Groups[2].Value
        if ($major -lt 8 -or ($major -eq 8 -and $minor -lt 3)) {
            Write-Fail "Found PHP $major.$minor on PATH, but GW-System needs PHP >= 8.3 (8.5 recommended)."
            Write-Fail "  Remove the old PHP from your PATH (or reorder it), then re-run this script."
            exit 1
        }
    }
    Write-Skip "PHP ($phpVer)"
}

# --- 3. php.ini + extensions ---------------------------------------------------
Write-Step "php.ini configuration"
$phpDir = Split-Path -Parent $php.Source
$iniLine = & php --ini 2>$null | Select-String "^Loaded Configuration File"
$iniPath = if ($iniLine) { ($iniLine.ToString() -split ":", 2)[1].Trim() } else { "" }
if (-not $iniPath -or -not (Test-Path $iniPath)) {
    $iniPath = Join-Path $phpDir "php.ini"
    if (-not (Test-Path $iniPath) -and (Test-Path (Join-Path $phpDir "php.ini-development"))) {
        Copy-Item (Join-Path $phpDir "php.ini-development") $iniPath
        Write-Host "    Created php.ini from php.ini-development template"
    }
}
if (-not (Test-Path $iniPath)) { Write-Fail "Could not find or create php.ini at $iniPath"; exit 1 }

function Set-IniValue {
    param([string[]]$lines, [string]$pattern, [string]$value)
    $found = $false
    for ($i = 0; $i -lt $lines.Count; $i++) {
        if ($lines[$i] -match $pattern) { $lines[$i] = $value; $found = $true }
    }
    if (-not $found) { $lines += $value }
    $lines
}

$extDir = Join-Path $phpDir "ext"
$lines = @(Get-Content -LiteralPath $iniPath)
$lines = Set-IniValue $lines '^;?\s*extension_dir\s*=' "extension_dir = `"$extDir`""
$lines = Set-IniValue $lines '^;?\s*memory_limit\s*=' "memory_limit = 512M"
foreach ($ext in "curl","openssl","pdo_pgsql","pgsql","mbstring","fileinfo","intl","zip") {
    $lines = Set-IniValue $lines "^;?\s*extension\s*=\s*$ext\b" "extension=$ext"
}
[System.IO.File]::WriteAllLines($iniPath, $lines)
Write-OK "extension_dir, memory_limit=512M and required extensions written to $iniPath"

$missing = @()
$mods = @(& php -m 2>$null)
foreach ($ext in "pdo_pgsql","pgsql","mbstring","intl","zip","bcmath") {
    if ($mods -notcontains $ext) { $missing += $ext }
}
if ($missing.Count) { Write-Fail "Still missing extensions: $($missing -join ', ') - check php.ini manually"; }
else { Write-OK "All required PHP extensions load" }

# --- 4. SSL CA bundle (cURL error 60 fix) ---------------------------------------
Write-Step "SSL CA bundle"
function Get-PhpIniValue {
    param([string]$name)
    $v = (& php -r "echo ini_get('$name');" 2>$null) | Select-Object -Last 1
    if ($null -eq $v) { return "" }
    return "$v".Trim()
}
$ca = Get-PhpIniValue "curl.cainfo"
if (-not $ca) { $ca = Join-Path $phpDir "cacert.pem" }
if (-not (Test-Path $ca)) {
    Write-Host "    Downloading Mozilla/curl CA bundle (curl.se)..."
    Invoke-WebRequest -Uri "https://curl.se/ca/cacert.pem" -OutFile $ca -UseBasicParsing
    $lines = @(Get-Content -LiteralPath $iniPath)
    $lines = Set-IniValue $lines '^;?\s*curl\.cainfo\s*=' "curl.cainfo = `"$ca`""
    $lines = Set-IniValue $lines '^;?\s*openssl\.cafile\s*=' "openssl.cafile = `"$ca`""
    [System.IO.File]::WriteAllLines($iniPath, $lines)
    Write-OK "CA bundle saved to $ca and wired into php.ini"
} else {
    Write-Skip "CA bundle ($ca)"
}
$verified = Get-PhpIniValue "curl.cainfo"
Write-OK "curl.cainfo = $verified"

# --- 5. Composer ----------------------------------------------------------------
Write-Step "Composer"
function Get-ComposerVersion {
    $old = $ErrorActionPreference
    $ErrorActionPreference = "Continue"
    try {
        $out = & cmd /c "composer --version" 2>$null
        if ($LASTEXITCODE -eq 0) { return ($out | Select-Object -First 1) }
        return ""
    } catch {
        return ""
    } finally {
        $ErrorActionPreference = $old
    }
}
function Test-Composer {
    return -not [string]::IsNullOrWhiteSpace((Get-ComposerVersion))
}
if (-not (Test-Composer)) {
    Write-Host "    Installing Composer..."
    $composerDir = "C:\composer"
    New-Item -ItemType Directory -Path $composerDir -Force | Out-Null
    Invoke-WebRequest -Uri "https://getcomposer.org/installer" -OutFile "$env:TEMP\composer-setup.php" -UseBasicParsing
    & php "$env:TEMP\composer-setup.php" --install-dir=$composerDir --filename=composer.phar
    Remove-Item "$env:TEMP\composer-setup.php" -ErrorAction SilentlyContinue
    @("@echo off", "@php `"%~dp0composer.phar`" %*") | Set-Content -Path (Join-Path $composerDir "composer.bat") -Encoding ASCII
    Add-UserPath $composerDir
    Refresh-Path
    if (-not (Test-Composer)) { Write-Fail "Composer not working - open a new terminal and re-run."; exit 1 }
    Write-OK "Composer installed to C:\composer and added to PATH"
} else {
    Write-Skip "Composer ($(Get-ComposerVersion))"
}

# --- 6. Node.js -------------------------------------------------------------------
Write-Step "Node.js"
if (-not (Get-Command node -ErrorAction SilentlyContinue)) {
    Write-Host "    Installing Node.js LTS via winget (this can take a minute)..."
    winget install --id OpenJS.NodeJS.LTS --source winget --accept-source-agreements --accept-package-agreements --silent
    Refresh-Path
    if (-not (Get-Command node -ErrorAction SilentlyContinue)) { Write-Fail "Node install finished but node is not on PATH - open a new terminal and re-run."; exit 1 }
    Write-OK "Node installed: $(& node -v)"
} else {
    Write-Skip "Node.js ($(& node -v))"
}

# --- 7. PostgreSQL ------------------------------------------------------------------
Write-Step "PostgreSQL 18"
function Find-PostgresBin {
    # 1) A running PostgreSQL service tells us exactly where the binaries live.
    $svc = Get-CimInstance Win32_Service -ErrorAction SilentlyContinue |
        Where-Object { $_.Name -like "postgresql*" -and $_.PathName } | Select-Object -First 1
    if ($svc) {
        $m = [regex]::Match($svc.PathName, '"([^"]*pg_ctl\.exe)"')
        if (-not $m.Success) { $m = [regex]::Match($svc.PathName, '([A-Za-z]:\\[^" ]*pg_ctl\.exe)') }
        if ($m.Success) {
            $exe = $m.Groups[1].Value
            if (Test-Path $exe) { return (Split-Path $exe) }
        }
    }
    # 2) EDB installer writes its install dir to the registry.
    foreach ($key in "HKLM:\SOFTWARE\PostgreSQL\Installations", "HKLM:\SOFTWARE\PostgreSQL\Services") {
        if (Test-Path $key) {
            foreach ($sub in Get-ChildItem $key -ErrorAction SilentlyContinue) {
                $base = (Get-ItemProperty $sub.PSPath -ErrorAction SilentlyContinue).'Base Directory'
                if ($base) {
                    $exe = Join-Path $base "bin\psql.exe"
                    if (Test-Path $exe) { return (Join-Path $base "bin") }
                }
            }
        }
    }
    # 3) Last resort: scan Program Files\PostgreSQL on every local drive.
    foreach ($drive in (Get-PSDrive -PSProvider FileSystem -ErrorAction SilentlyContinue)) {
        foreach ($pf in "Program Files", "Program Files (x86)") {
            $pgRoot = Join-Path $drive.Root $pf
            $pgRoot = Join-Path $pgRoot "PostgreSQL"
            if (Test-Path $pgRoot) {
                $match = Get-ChildItem $pgRoot -Directory -ErrorAction SilentlyContinue |
                    Sort-Object Name -Descending |
                    ForEach-Object { Join-Path $_.FullName "bin\psql.exe" } |
                    Where-Object { Test-Path $_ } | Select-Object -First 1
                if ($match) { return (Split-Path $match) }
            }
        }
    }
    return ""
}
$psql = Get-Command psql -ErrorAction SilentlyContinue
if (-not $psql) {
    $pgBin = Find-PostgresBin
    if ($pgBin) {
        Write-Host "    Found an existing PostgreSQL install at $pgBin (not on PATH)"
        Add-UserPath $pgBin
        $psql = Get-Command psql -ErrorAction SilentlyContinue
    }
    if (-not $psql) {
        Write-Host "    Installing PostgreSQL 18 via winget (this can take a minute)..."
        winget install --id PostgreSQL.PostgreSQL.18 --source winget --accept-source-agreements --accept-package-agreements --silent
        Refresh-Path
        $psql = Get-Command psql -ErrorAction SilentlyContinue
        if (-not $psql) {
            Write-Host "    psql not on PATH - looking for the install..."
            $pgBin = Find-PostgresBin
            if ($pgBin) {
                Add-UserPath $pgBin
                $psql = Get-Command psql -ErrorAction SilentlyContinue
            }
        }
        if (-not $psql) {
            Write-Fail "PostgreSQL not found after install. If a GUI installer opened, finish it"
            Write-Fail "  (superuser password: $DbPassword), then open a new terminal and re-run this script."
            exit 1
        }
    }
    Write-OK "PostgreSQL ready; bin added to PATH ($(Split-Path -Parent $psql.Source))"
} else {
    Write-Skip "PostgreSQL (psql at $($psql.Source))"
}

# --- 8. Databases ---------------------------------------------------------------------
Write-Step "Databases (gw_system, gw_system_testing)"
$svc = Get-Service -ErrorAction SilentlyContinue | Where-Object { $_.Name -like "*postgresql*" } | Select-Object -First 1
if ($svc -and $svc.Status -ne "Running") { Start-Service $svc.Name; Start-Sleep -Seconds 3 }
$env:PGPASSWORD = $DbPassword
foreach ($db in "gw_system", "gw_system_testing") {
    try {
        $exists = & psql -U postgres -h 127.0.0.1 -t -A -c "SELECT 1 FROM pg_database WHERE datname='$db';" 2>$null
        if ("$exists".Trim() -eq "1") { Write-Skip "database $db" }
        else {
            & psql -U postgres -h 127.0.0.1 -c "CREATE DATABASE $db;" 2>$null
            Write-OK "database $db created"
        }
    } catch {
        Write-Fail "Could not reach Postgres as user 'postgres' (password '$DbPassword')."
        Write-Fail "  If your superuser password differs, re-run:  setup.ps1 -DbPassword '<yours>'"
        exit 1
    }
}
Remove-Item Env:\PGPASSWORD -ErrorAction SilentlyContinue

# --- 9. Backend setup -----------------------------------------------------------------
Write-Step "Backend (composer install, .env, key, migrate, seed)"
Set-Location $backend
if (Test-Path "vendor") { Write-Skip "composer install" }
else {
    Write-Host "    composer install (this can take a few minutes)..."
    $old = $ErrorActionPreference
    $ErrorActionPreference = "Continue"
    & cmd /c "composer install"
    $code = $LASTEXITCODE
    $ErrorActionPreference = $old
    if ($code -ne 0) { Write-Fail "composer install failed"; exit 1 }
    Write-OK "dependencies installed"
}
if (-not (Test-Path ".env")) { Copy-Item ".env.example" ".env"; Write-OK ".env created from .env.example" }
else { Write-Skip ".env" }
$appKey = ((Select-String -Path ".env" -Pattern "^APP_KEY=").Line -replace "^APP_KEY=", "")
if (-not $appKey) { & php artisan key:generate; Write-OK "APP_KEY generated" }
else { Write-Skip "APP_KEY" }
Write-Host "    Running migrations..."
& php artisan migrate --force
if ($LASTEXITCODE -ne 0) { Write-Fail "migrate failed - check backend/.env DB_* settings"; exit 1 }
Write-OK "migrations applied"
& php artisan db:seed
Write-OK "seed data applied (admin@gwsystem.com / test@example.com)"

# --- 10. Frontend setup ---------------------------------------------------------------
Write-Step "Frontend (npm install)"
Set-Location $frontend
if (Test-Path "node_modules") { Write-Skip "npm install" }
else {
    Write-Host "    npm install (this can take a few minutes)..."
    & npm install
    if ($LASTEXITCODE -ne 0) { Write-Fail "npm install failed"; exit 1 }
    Write-OK "frontend dependencies installed"
}

# --- Done -------------------------------------------------------------------------------
Write-Host ""
Write-Host "========================================================" -ForegroundColor Green
Write-Host "  Setup complete!" -ForegroundColor Green
Write-Host "  Next: double-click start.bat (or run it) to launch:" -ForegroundColor Green
Write-Host "    Portal  http://localhost:3000   (test@example.com / password)" -ForegroundColor Gray
Write-Host "    Admin   http://127.0.0.1:8000/admin   (admin@gwsystem.com / admin123)" -ForegroundColor Gray
Write-Host "  Sanity check anytime:  verify.ps1" -ForegroundColor Gray
Write-Host "========================================================" -ForegroundColor Green
Write-Host ""
