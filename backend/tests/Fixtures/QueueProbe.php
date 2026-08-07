<?php

namespace Tests\Fixtures;

/**
 * In-process side-effect recorder used by the queue-worker smoke test.
 * The worker runs inside the test process, so the static array is shared
 * between the dispatcher and the processed job.
 */
final class QueueProbe
{
    /** @var list<string> */
    public static array $ran = [];

    public static function reset(): void
    {
        self::$ran = [];
    }

    public static function record(string $value): void
    {
        self::$ran[] = $value;
    }
}
