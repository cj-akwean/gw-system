<#
GW-System - full uninstaller

Removes everything setup.ps1 created, in 4 confirmed stages:
  1. Project generated files - backend/vendor, backend/.env, frontend/node_modules,
                              frontend/.next, Laravel storage runtime (logs, framework cache/sessions/views)
  2. Databases               - gw_system, gw_system_testing (DROP ... WITH (FORCE))
  3. Installed tools         - C:\composer dir, PATH entries we added,
                              then winget: PHP 8.5, Node LTS, PostgreSQL 18
  4. Whole project folder    - the gw-system directory itself (including this script)

NOT reverted: php.ini edits from setup (memory_limit, enabled extensions,
curl.cainfo / openssl.cafile). Those only disappear if PHP is uninstalled
(stage 3). If you keep PHP, its php.ini stays configured - harmless.

Read-only until you confirm each stage. Always prompts; -Yes auto-confirms
stages 1-3 (stage 4 always asks you to type the folder name as a typo guard).

Usage:
  .\uninstall.ps1                  interactive
  .\uninstall.ps1 -Yes             auto-confirm stages 1-3
  .\uninstall.ps1 -DryRun          list what would be removed, change nothing
  .\uninstall.ps1 -DbPassword "x"  Postgres superuser password (default postgres)

Tips:
  - Stop dev servers (Ctrl+C on the start.bat windows) before running.
  - To delete the project folder itself, run from OUTSIDE it
    (e.g. C:\Users\you) - the script relocates to %TEMP% first anyway.
#>
param(
    [switch]$Yes,
    [switch]$DryRun,
    [string]$DbPassword = "postgres"
)

$ErrorActionPreference = "Continue"

$root     = Split-Path -Parent $MyInvocation.MyCommand.Path
$backend  = Join-Path $root "backend"
$frontend = Join-Path $root "frontend"

Write-Host ""
Write-Host "========================================================" -ForegroundColor Cyan
Write-Host "  GW-System uninstall" -ForegroundColor Cyan
Write-Host "  Machine: $env:COMPUTERNAME  |  $(Get-Date -Format 'yyyy-MM-dd HH:mm')" -ForegroundColor DarkGray
Write-Host "  Target:  $root" -ForegroundColor DarkGray
if ($DryRun) { Write-Host "  MODE:    DRY RUN - nothing will be changed" -ForegroundColor Yellow }
Write-Host "========================================================" -ForegroundColor Cyan

function Write-Step { param([string]$msg) Write-Host ""; Write-Host "==> $msg" -ForegroundColor Cyan }
function Write-OK   { param([string]$msg) Write-Host "    OK  $msg" -ForegroundColor Green }
function Write-Info { param([string]$msg) Write-Host "    --  $msg" -ForegroundColor DarkGray }
function Write-Fail { param([string]$msg) Write-Host "    !!  $msg" -ForegroundColor Yellow }

function Confirm-Stage {
    param([string]$title)
    if ($DryRun -or $Yes) { return $true }
    $reply = Read-Host "    Remove '$title'? [y/N]"
    return ($reply -match '^(y|yes)$')
}

function Remove-UserPathEntry {
    param([string]$entry)
    $userPath = [Environment]::GetEnvironmentVariable("Path", "User")
    if (-not $userPath) { return }
    $kept = @($userPath -split ';' | Where-Object {
        $_ -and ($_.TrimEnd('\') -ne $entry.TrimEnd('\'))
    })
    $new = $kept -join ';'
    if ($new -ne $userPath) { [Environment]::SetEnvironmentVariable("Path", $new, "User") }
}

function Find-Psql {
    $cmd = Get-Command psql -ErrorAction SilentlyContinue
    if ($cmd) { return $cmd.Source }
    foreach ($drive in (Get-PSDrive -PSProvider FileSystem -ErrorAction SilentlyContinue)) {
        foreach ($pf in "Program Files", "Program Files (x86)") {
            $pgRoot = Join-Path (Join-Path $drive.Root $pf) "PostgreSQL"
            if (Test-Path $pgRoot) {
                $match = Get-ChildItem $pgRoot -Directory -ErrorAction SilentlyContinue |
                    Sort-Object Name -Descending |
                    ForEach-Object { Join-Path $_.FullName "bin\psql.exe" } |
                    Where-Object { Test-Path $_ } | Select-Object -First 1
                if ($match) { return $match }
            }
        }
    }
    return ""
}

# --- 1. Project generated files ---------------------------------------------
Write-Step "1/4 Project generated files"
if (Confirm-Stage "generated files (vendor, .env, node_modules, .next, storage runtime)") {
    $targets = @()
    foreach ($p in (Join-Path $backend "vendor"),
                   (Join-Path $backend ".env"),
                   (Join-Path $frontend "node_modules"),
                   (Join-Path $frontend ".next")) {
        if (Test-Path $p) { $targets += $p }
    }
    if (-not $targets.Count) {
        Write-Info "nothing to remove"
    } else {
        foreach ($p in $targets) {
            if ($DryRun) { Write-Info "would remove $p" }
            else {
                Remove-Item -LiteralPath $p -Recurse -Force -ErrorAction SilentlyContinue
                if (Test-Path $p) { Write-Fail "could not remove $p (file in use? close dev servers and re-run)" }
                else { Write-OK "removed $p" }
            }
        }
    }
    # Laravel runtime dirs: clear contents but KEEP the tracked .gitignore files.
    $storageDirs = @()
    foreach ($p in (Join-Path $backend "storage\logs"),
                   (Join-Path $backend "storage\framework\cache"),
                   (Join-Path $backend "storage\framework\sessions"),
                   (Join-Path $backend "storage\framework\views")) {
        if (Test-Path $p) { $storageDirs += $p }
    }
    foreach ($dir in $storageDirs) {
        if ($DryRun) { Write-Info "would clear runtime contents of $dir (keep .gitignore)" }
        else {
            Get-ChildItem -LiteralPath $dir -Force | Where-Object { $_.Name -ne ".gitignore" } |
                Remove-Item -Recurse -Force -ErrorAction SilentlyContinue
            Write-OK "cleared $dir (kept .gitignore)"
        }
    }
} else {
    Write-Info "skipped"
}

# --- 2. Databases --------------------------------------------------------------
Write-Step "2/4 Databases (gw_system, gw_system_testing)"
$psqlCmd = Find-Psql
if (-not $psqlCmd) {
    Write-Info "psql not found - databases not dropped"
} elseif (Confirm-Stage "databases gw_system, gw_system_testing") {
    $env:PGPASSWORD = $DbPassword
    foreach ($db in "gw_system", "gw_system_testing") {
        $sql = "DROP DATABASE IF EXISTS $db WITH (FORCE);"
        if ($DryRun) { Write-Info "would run: psql -U postgres -h 127.0.0.1 -c `"$sql`"" }
        else {
            & $psqlCmd -U postgres -h 127.0.0.1 -v ON_ERROR_STOP=1 -c $sql 2>$null | Out-Null
            if ($LASTEXITCODE -eq 0) { Write-OK "dropped $db" }
            else { Write-Fail "could not drop $db (wrong superuser password? re-run with -DbPassword)" }
        }
    }
    Remove-Item Env:\PGPASSWORD -ErrorAction SilentlyContinue
} else {
    Write-Info "skipped"
}

# --- 3. Installed tools ----------------------------------------------------------
Write-Step "3/4 Installed tools (Composer, PHP, Node, PostgreSQL)"
if (Confirm-Stage "installed tools (C:\composer, PATH entries, winget packages PHP/Node/PostgreSQL)") {
    # Composer install dir
    if (Test-Path "C:\composer") {
        if ($DryRun) { Write-Info "would remove C:\composer" }
        else {
            Remove-Item -LiteralPath "C:\composer" -Recurse -Force -ErrorAction SilentlyContinue
            if (Test-Path "C:\composer") { Write-Fail "could not remove C:\composer (in use?)" }
            else { Write-OK "removed C:\composer" }
        }
    } else {
        Write-Info "C:\composer not present"
    }

    # PATH entries this setup added
    $userPath = [Environment]::GetEnvironmentVariable("Path", "User")
    foreach ($entry in @($userPath -split ';')) {
        if (-not $entry) { continue }
        $trimmed = $entry.TrimEnd('\')
        $isOurs = ($trimmed -eq "C:\composer") -or
                  ($trimmed -match '\\PostgreSQL\\\d+\bin$') -or
                  ($trimmed -match 'WinGet\\Packages\\PHP\.PHP\.8\.5')
        if ($isOurs) {
            if ($DryRun) { Write-Info "would remove PATH entry: $entry" }
            else { Remove-UserPathEntry $entry; Write-OK "removed PATH entry: $entry" }
        }
    }

    # winget packages
    if ($DryRun) {
        Write-Info "would winget uninstall: PHP.PHP.8.5, OpenJS.NodeJS.LTS, PostgreSQL.PostgreSQL.18"
    } else {
        foreach ($id in "PHP.PHP.8.5", "OpenJS.NodeJS.LTS", "PostgreSQL.PostgreSQL.18") {
            Write-Host "    winget uninstall $id ..."
            winget uninstall --id $id --source winget --accept-source-agreements --accept-package-agreements --silent
            if ($LASTEXITCODE -eq 0) { Write-OK "uninstalled $id" }
            else { Write-Info "$id not uninstalled (not installed, or requires another run)" }
        }
    }
} else {
    Write-Info "skipped"
}

# --- 4. Whole project folder -------------------------------------------------------
Write-Step "4/4 Project folder ($root)"
$driveRoot = [System.IO.Path]::GetPathRoot($root).TrimEnd('\')
$rootTrim  = $root.TrimEnd('\')
if ($rootTrim -eq $driveRoot) {
    Write-Fail "Refusing to delete the drive root ($root)."
} else {
    $name = Split-Path -Leaf $root
    $proceed = $false
    if ($DryRun) {
        $proceed = $true
    } else {
        $typed = Read-Host "    Delete the whole folder? Type the folder name ($name) to confirm"
        $proceed = ($typed -eq $name)
        if (-not $proceed) { Write-Fail "name mismatch - skipped" }
    }
    if ($proceed) {
        if ($DryRun) {
            Write-Info "would delete $root"
        } else {
            Set-Location $env:TEMP
            Remove-Item -LiteralPath $root -Recurse -Force -ErrorAction SilentlyContinue
            if (Test-Path $root) {
                Write-Fail "Could not fully delete $root - a file is in use. Close terminals that have it open and re-run."
            } else {
                Write-OK "deleted $root"
            }
        }
    }
}

$doneMsg = if ($DryRun) { "Uninstall would have run (dry run) - nothing changed" } else { "Uninstall complete" }
Write-Host ""
Write-Host "========================================================" -ForegroundColor Green
Write-Host "  $doneMsg" -ForegroundColor Green
Write-Host "========================================================" -ForegroundColor Green
Write-Host ""
