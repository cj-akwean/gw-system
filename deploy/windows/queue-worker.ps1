<#
.SYNOPSIS
    Runs the GW-System Laravel queue worker with the project's production-grade flags.

.DESCRIPTION
    Intended to be registered as a Windows Scheduled Task (see register-worker.ps1).
    The worker polls the `database` queue driver continuously, and the process
    restarts itself every 8 hours (`--max-time`, memory + stale-config hygiene)
    without the Task Scheduler noticing. A non-zero worker exit (crash) is
    propagated so the task's restart-on-failure settings kick in.

    Windows PHP prerequisite: the worker must run under the winget PHP 8.5 build
    (has pdo_pgsql). Herd Lite PHP lacks the driver and is rejected with an error.

.EXAMPLE
    powershell -NoProfile -ExecutionPolicy Bypass -File deploy\windows\queue-worker.ps1

.EXAMPLE
    powershell -NoProfile -ExecutionPolicy Bypass -File deploy\windows\queue-worker.ps1 -Php C:\path\to\php.exe
#>
[CmdletBinding()]
param(
    # Explicit path to php.exe. Detected from PATH when omitted (pgsql checked).
    [string]$Php = '',
    # Seconds per worker process before a fresh one replaces it (default 8h).
    [int]$MaxTimeSeconds = 28800,
    # Transcript log path. Defaults to <backend>/storage/logs/queue-worker.log
    [string]$Log = ''
)

$ErrorActionPreference = 'Stop'

$Backend = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..\backend'))

if (-not $Php) {
    $command = Get-Command php -ErrorAction SilentlyContinue
    if ($command) {
        $Php = $command.Source
    } else {
        throw "php not found on PATH. Pass -Php <path-to-php.exe>."
    }
}

$phpModules = & $Php -m
if (-not ($phpModules | Where-Object { $_ -eq 'pdo_pgsql' })) {
    throw "PHP at '$Php' has no pdo_pgsql extension. Use the winget PHP 8.5 build (README -> PHP Notes)."
}

if (-not (Test-Path -LiteralPath (Join-Path $Backend 'artisan'))) {
    throw "artisan not found under '$Backend'. Run this script from the repository checkout."
}

if (-not $Log) {
    $Log = Join-Path $Backend 'storage\logs\queue-worker.log'
}

Start-Transcript -Path $Log -Append -Force | Out-Null
Write-Host "[queue-worker] PHP: $Php"
Write-Host "[queue-worker] Backend: $Backend"
Write-Host "[queue-worker] Log: $Log"
Write-Host "[queue-worker] Use 'php artisan queue:restart' after .env / queue config changes."

Push-Location $Backend
try {
    while ($true) {
        Write-Host "[queue-worker] launching: php artisan queue:work --queue=default --tries=3 --timeout=120 --sleep=3 --max-time=$MaxTimeSeconds"
        & $Php artisan queue:work --queue=default --tries=3 --timeout=120 --sleep=3 --max-time=$MaxTimeSeconds
        $code = $LASTEXITCODE
        if ($code -eq 0) {
            Write-Host "[queue-worker] worker exited cleanly (max-time reached). Restarting in 5s..."
            Start-Sleep -Seconds 5
        } else {
            Write-Host "[queue-worker] worker exited with code $code - dying so the Task Scheduler restarts it."
            exit $code
        }
    }
}
finally {
    Pop-Location
    Stop-Transcript | Out-Null
}
