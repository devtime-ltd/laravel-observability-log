<?php

use DevtimeLtd\LaravelObservabilityLog\Tests\TestCase;
use Illuminate\Contracts\Queue\Job as JobContract;

uses(TestCase::class)->in(__DIR__);

function fakeJob(array $overrides = []): JobContract
{
    $defaults = [
        'resolveName' => 'App\\Jobs\\SendEmail',
        'getName' => 'App\\Jobs\\SendEmail',
        'getJobId' => 'job-1',
        'getQueue' => 'default',
        'attempts' => 1,
        'maxTries' => 3,
    ];

    $values = array_merge($defaults, $overrides);

    $mock = Mockery::mock(JobContract::class);
    foreach ($values as $method => $return) {
        $mock->shouldReceive($method)->andReturn($return);
    }

    return $mock;
}
