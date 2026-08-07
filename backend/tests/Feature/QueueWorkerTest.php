<?php

namespace Tests\Feature;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Tests\Fixtures\QueuedProbeJob;
use Tests\Fixtures\QueueProbe;
use Tests\TestCase;

/**
 * Verifies the deployed queue setup for the "Queue worker running (database
 * driver)" checklist item:
 *
 * 1. A job dispatched into the real `database` queue sits in `jobs` until a
 *    worker processes it — driven through the same `queue:work` console command
 *    (same `Illuminate\Queue\Worker`) the durable worker script runs.
 * 2. The shipped queue config falls back to `database` when `QUEUE_CONNECTION`
 *    is absent.
 * 3. Every queued job declares its own retry policy (>= 3), because dev
 *    convenience runners (e.g. `composer dev`) can start workers with
 *    `--tries=1` and that must never throttle a real job's retries.
 */
class QueueWorkerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        QueueProbe::reset();
        config(['queue.default' => 'database']);
    }

    public function test_database_queue_job_is_processed_by_the_worker_command(): void
    {
        QueuedProbeJob::dispatch('ping');

        $this->assertSame(1, DB::table('jobs')->count(), 'Job should sit in `jobs` before the worker runs.');
        $this->assertSame([], QueueProbe::$ran);

        $this->artisan('queue:work', [
            '--queue' => 'default',
            '--once' => true,
            '--stop-when-empty' => true,
            '--tries' => 3,
            '--timeout' => 5,
            '--sleep' => 1,
            '--max-time' => 10,
        ])->assertSuccessful();

        $this->assertSame(0, DB::table('jobs')->count(), 'worker must drain the processed job.');
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(['ping'], QueueProbe::$ran, 'worker must run the job and record its side effect.');
    }

    public function test_queue_driver_defaults_to_database_without_env_override(): void
    {
        $previousGetenv = getenv('QUEUE_CONNECTION');
        $hasServerKey = array_key_exists('QUEUE_CONNECTION', $_SERVER);
        $previousServer = $hasServerKey ? $_SERVER['QUEUE_CONNECTION'] : null;
        $hasEnvKey = array_key_exists('QUEUE_CONNECTION', $_ENV);
        $previousEnv = $hasEnvKey ? $_ENV['QUEUE_CONNECTION'] : null;

        putenv('QUEUE_CONNECTION');
        unset($_SERVER['QUEUE_CONNECTION'], $_ENV['QUEUE_CONNECTION']);

        try {
            $queueConfig = require config_path('queue.php');
            $this->assertSame('database', $queueConfig['default']);
        } finally {
            if ($previousGetenv !== false) {
                putenv('QUEUE_CONNECTION='.$previousGetenv);
            }
            if ($hasServerKey) {
                $_SERVER['QUEUE_CONNECTION'] = $previousServer;
            }
            if ($hasEnvKey) {
                $_ENV['QUEUE_CONNECTION'] = $previousEnv;
            }
        }
    }

    public function test_every_queueable_job_declares_explicit_tries_of_at_least_3(): void
    {
        $files = glob(app_path('Jobs/*.php'));
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $class = 'App\\Jobs\\'.basename($file, '.php');
            $reflection = new ReflectionClass($class);

            $this->assertTrue(
                $reflection->implementsInterface(ShouldQueue::class),
                "$class should implement ".ShouldQueue::class.'.'
            );
            $this->assertTrue(
                $reflection->hasProperty('tries'),
                "$class should declare an explicit \$tries property."
            );
            $this->assertGreaterThanOrEqual(
                3,
                $reflection->getProperty('tries')->getDefaultValue(),
                "$class \$tries must be >= 3 — dev workers can run with --tries=1, and job-level tries wins only when set."
            );
        }
    }
}
