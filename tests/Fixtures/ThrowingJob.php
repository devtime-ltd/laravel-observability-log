<?php

namespace DevtimeLtd\LaravelObservabilityLog\Tests\Fixtures;

use RuntimeException;

class ThrowingJob
{
    public function handle(): void
    {
        throw new RuntimeException('boom from job');
    }
}
