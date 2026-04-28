<?php

namespace DevtimeLtd\LaravelObservabilityLog\Tests\Fixtures;

use Illuminate\Support\Facades\DB;

class IntegrationTestJob
{
    public function __construct(public bool $runQuery = false)
    {
    }

    public function handle(): void
    {
        if ($this->runQuery) {
            DB::table('users')->get();
        }
    }
}
