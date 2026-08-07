<#
.SYNOPSIS
    Registers / removes / inspects the 'GW-System Queue Worker' Windows Scheduled Task.

.DESCRIPTION
    The task runs deploy\windows\queue-worker.ps1 at logon, automatically restarts
    the worker up to 3x on a crash, and tolerates the worker's daily self-restart.
    Idempotent - safe to re-run after repository changes.

    Before registering, the script runs the worker once (--once --stop-when-empty) to
    confirm PHP + the database queue driver respond, then starts the task.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File deploy\windows\register-worker.ps1

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File deploy\windows\register-worker.ps1 -Status

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File deploy\windows\register-worker.ps1 -Unregister
#>
[CmdletBinding()]
param(
    # Stop and unregister the task.
    [switch]$Unregister,
    # Show the task's current state, last run result, and exit.
    [switch]$Status
)

$ErrorActionPreference = 'Stop'

$TaskName = 'GW-System Queue Worker'
$Root      = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
$Worker    = Join-Path $PSScriptRoot 'queue-worker.ps1'
$Backend   = Join-Path $Root 'backend'

if ($Unregister) {
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false -ErrorAction SilentlyContinue
    Write-Host "[register-worker] '$TaskName' unregistered."
    exit 0
}

if ($Status) {
    $task = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
    if (-not $task) {
        Write-Host "Not registered. Run register-worker.ps1 (no flags)."
        exit 0
    }
    $info = Get-ScheduledTaskInfo -TaskName $TaskName
    Write-Host ("State: {0} | Last run: {1} | Last result: {2}" -f $task.State, $info.LastRunTime, $info.LastTaskResult)
    Write-Host ("Log: {0}" -f (Join-Path $Backend 'storage\logs\queue-worker.log'))
    exit 0
}

# Sanity: the worker can start and the queue drains once before we daemonize it.
Write-Host '[register-worker] smoke: php artisan queue:work --once (please wait)'
Push-Location $Backend
try {
    & php artisan queue:work --once --stop-when-empty
    if ($LASTEXITCODE -ne 0) {
        throw "queue:work --once failed ($LASTEXITCODE). Fix the worker before registering a task."
    }
}
finally {
    Pop-Location
}

# Task Scheduler registration requires an elevated shell.
$principal = [Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()
$isAdmin = $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $isAdmin) {
    Write-Host 'Registering a Scheduled Task requires an administrator shell.'
    Write-Host 'Rerun elevated, e.g.:'
    Write-Host '    Start-Process powershell -Verb RunAs -ArgumentList "-NoProfile -ExecutionPolicy Bypass -File `"$PSCommandPath`""'
    exit 1
}

$action   = New-ScheduledTaskAction -Execute 'powershell.exe' `
    -Argument "-NoProfile -ExecutionPolicy Bypass -File `"$Worker`""
$trigger  = New-ScheduledTaskTrigger -AtLogOn
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries -StartWhenAvailable `
    -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1) `
    -ExecutionTimeLimit ([TimeSpan]::Zero) -MultipleInstances IgnoreNew

Register-ScheduledTask `
    -TaskName $TaskName `
    -Action $action `
    -Trigger $trigger `
    -Settings $settings `
    -Description 'GW-System Laravel queue worker (database driver). Runs deploy\windows\queue-worker.ps1.' `
    -Force | Out-Null

Start-ScheduledTask -TaskName $TaskName
Write-Host "[register-worker] registered + started. Manage in Task Scheduler Library."
Write-Host "[register-worker] log: $(Join-Path $Backend 'storage\logs\queue-worker.log')"