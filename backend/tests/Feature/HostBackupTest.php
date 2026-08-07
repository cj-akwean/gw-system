<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Locks in the host-side backup artifacts (deploy/linux/) — the half of the
 * Infra-phase cron that Laravel's scheduler deliberately does NOT own.
 *
 * The app-side scheduler (routes/console.php) is pinned by ScheduleTest; this
 * test pins the matching host pieces: the single `schedule:run` tick, the
 * 02:30 pg_dump line (before the 03:05 billing run), and the invariants of
 * backup.sh / restore-drill.sh that make a daily dump actually restorable.
 */
class HostBackupTest extends TestCase
{
    private function repoRoot(): string
    {
        return realpath(dirname(__DIR__, 3));
    }

    private function deployFile(string $name): string
    {
        $path = $this->repoRoot().'/deploy/linux/'.$name;

        $this->assertFileExists($path, "deploy artifact missing: $name");

        return $path;
    }

    private function readDeployFile(string $name): string
    {
        return (string) file_get_contents($this->deployFile($name));
    }

    #[Test]
    public function host_cron_ships_the_single_scheduler_tick_and_the_daily_backup_line(): void
    {
        $cron = $this->readDeployFile('cron-gw-system');

        $this->assertMatchesRegularExpression(
            '/^\* \* \* \* \* .*artisan schedule:run/sm',
            $cron,
            'The one mandatory host cron line (schedule:run every minute) must exist.'
        );

        $this->assertMatchesRegularExpression(
            '/^30 2 \* \* \* root .*backup\.sh/sm',
            $cron,
            'The daily backup must fire at 02:30 — before the 03:05 billing run.'
        );

        $this->assertMatchesRegularExpression(
            '/^SHELL=\/bin\/bash/m',
            $cron,
            'cron.d must run bash so backup.sh features (mapfile, compgen) work.'
        );
    }

    #[Test]
    public function backup_script_is_a_failsafe_rotating_verified_dump(): void
    {
        $script = $this->readDeployFile('backup.sh');

        $this->assertMatchesRegularExpression('/set -euo pipefail/', $script, 'Any failure must stop the script loudly.');
        $this->assertMatchesRegularExpression('/-Fc/', $script, 'pg_dump must use the portable custom format.');
        $this->assertMatchesRegularExpression('/BACKUP_KEEP="\$\{BACKUP_KEEP:-15\}"/', $script, 'Default retention is 15 dumps.');
        $this->assertMatchesRegularExpression('/flock 9/', $script, 'Overlapping runs must be serialized (same-second filename collision).');
        $this->assertMatchesRegularExpression('/compgen -G .*gw_system_\*\.dump/', $script, 'Rotation must use compgen so an empty dir cannot abort under set -e.');
        $this->assertMatchesRegularExpression('/-l "\$DUMP"/', $script, 'A new dump must be verified restorable (pg_restore -l) before the run reports ok.');
    }

    #[Test]
    public function restore_drill_is_scratch_database_isolated(): void
    {
        $drill = $this->readDeployFile('restore-drill.sh');

        $this->assertMatchesRegularExpression('/set -euo pipefail/', $drill);
        $this->assertMatchesRegularExpression('/gw_drill_/', $drill, 'The drill must restore into a clearly-marked scratch database.');
        $this->assertMatchesRegularExpression('/CREATE DATABASE/', $drill);
        $this->assertMatchesRegularExpression('/DROP DATABASE IF EXISTS/', $drill, 'The scratch DB must be dropped on exit.');
        $this->assertMatchesRegularExpression('/service_connections/', $drill, 'The drill must sanity-check core money tables.');
        $this->assertMatchesRegularExpression('/invoices/', $drill);
    }

    #[Test]
    public function runbook_documents_both_backup_artifacts(): void
    {
        $runbook = (string) file_get_contents($this->repoRoot().'/docs/deployment-runbook.md');

        $this->assertStringContainsString('deploy/linux/backup.sh', $runbook);
        $this->assertStringContainsString('restore-drill.sh', $runbook);
        $this->assertStringContainsString('/etc/gw-backup.env', $runbook, 'The runbook must warn about the dev-only credentials default.');
    }
}
