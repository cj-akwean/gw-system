<?php

namespace Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Minimal, dependency-free job serialized through the real `database` queue
 * driver. Mirrors the project's retry policy so the smoke test exercises the
 * same worker semantics as production jobs.
 */
class QueuedProbeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public string $value) {}

    public function handle(): void
    {
        QueueProbe::record($this->value);
    }
}
