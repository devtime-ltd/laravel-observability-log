<?php

namespace DevtimeLtd\LaravelObservabilityLog\Tests;

use DevtimeLtd\LaravelObservabilityLog\ObservabilityLogServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [ObservabilityLogServiceProvider::class];
    }
}
