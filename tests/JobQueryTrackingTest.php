<?php

use DevtimeLtd\LaravelObservabilityLog\JobSensor;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    JobSensor::using(null);
    JobSensor::extend(null);
    JobSensor::message(null);
    Context::flush();
    app()->forgetInstance(JobSensor::class);

    config([
        'database.default' => 'testing',
        'database.connections.testing' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ],
    ]);

    Schema::create('test_items', function ($table) {
        $table->id();
        $table->string('name');
    });
});

it('counts database queries during a job attempt', function () {
    config(['observability-log.jobs.channel' => 'test']);

    $captured = null;
    $channel = Mockery::mock();
    $channel->shouldReceive('log')
        ->once()
        ->andReturnUsing(function ($level, $message, $context) use (&$captured) {
            $captured = $context;
        });

    Log::shouldReceive('channel')->with('test')->andReturn($channel);

    $job = fakeJob();
    JobSensor::recordProcessing(new JobProcessing('redis', $job));

    DB::table('test_items')->insert(['name' => 'one']);
    DB::table('test_items')->insert(['name' => 'two']);
    DB::table('test_items')->get();

    JobSensor::recordProcessed(new JobProcessed('redis', $job));

    expect($captured['db_query_count'])->toBe(3);
    expect($captured['db_query_total_ms'])->toBeFloat();
});

it('captures slow queries above threshold for jobs', function () {
    config([
        'observability-log.jobs.channel' => 'test',
        'observability-log.jobs.slow_query_threshold' => 0,
    ]);

    $captured = null;
    $channel = Mockery::mock();
    $channel->shouldReceive('log')
        ->once()
        ->andReturnUsing(function ($level, $message, $context) use (&$captured) {
            $captured = $context;
        });

    Log::shouldReceive('channel')->with('test')->andReturn($channel);

    $job = fakeJob();
    JobSensor::recordProcessing(new JobProcessing('redis', $job));
    DB::table('test_items')->get();
    JobSensor::recordProcessed(new JobProcessed('redis', $job));

    expect($captured['db_slow_queries'])->toHaveCount(1);
    expect($captured['db_slow_queries'][0]['sql'])->toBeString();
    expect($captured['db_slow_queries'][0]['connection'])->toBe('testing');
});

it('does not capture slow queries when threshold is null', function () {
    config([
        'observability-log.jobs.channel' => 'test',
        'observability-log.jobs.slow_query_threshold' => null,
    ]);

    $captured = null;
    $channel = Mockery::mock();
    $channel->shouldReceive('log')
        ->once()
        ->andReturnUsing(function ($level, $message, $context) use (&$captured) {
            $captured = $context;
        });

    Log::shouldReceive('channel')->with('test')->andReturn($channel);

    $job = fakeJob();
    JobSensor::recordProcessing(new JobProcessing('redis', $job));
    DB::table('test_items')->get();
    JobSensor::recordProcessed(new JobProcessed('redis', $job));

    expect($captured['db_query_count'])->toBe(1);
    expect($captured)->not->toHaveKey('db_slow_queries');
});

it('caps slow queries via slow_queries_max_count and appends a truncation marker', function () {
    config([
        'observability-log.jobs.channel' => 'test',
        'observability-log.jobs.slow_query_threshold' => 0,
        'observability-log.jobs.slow_queries_max_count' => 2,
    ]);

    $captured = null;
    $channel = Mockery::mock();
    $channel->shouldReceive('log')
        ->once()
        ->andReturnUsing(function ($level, $message, $context) use (&$captured) {
            $captured = $context;
        });

    Log::shouldReceive('channel')->with('test')->andReturn($channel);

    $job = fakeJob();
    JobSensor::recordProcessing(new JobProcessing('redis', $job));
    DB::table('test_items')->get();
    DB::table('test_items')->get();
    DB::table('test_items')->get();
    DB::table('test_items')->get();
    JobSensor::recordProcessed(new JobProcessed('redis', $job));

    expect($captured['db_slow_queries'])->toHaveCount(3);
    expect(end($captured['db_slow_queries']))->toHaveKey('truncated');
    expect($captured['db_slow_queries'][2]['truncated'])->toContain('2 more slow queries dropped');
});

it('does not bleed query counts between consecutive job attempts', function () {
    config([
        'observability-log.jobs.channel' => 'test',
    ]);

    $captured = [];
    $channel = Mockery::mock();
    $channel->shouldReceive('log')
        ->twice()
        ->andReturnUsing(function ($level, $message, $context) use (&$captured) {
            $captured[] = $context;
        });

    Log::shouldReceive('channel')->with('test')->andReturn($channel);

    $first = fakeJob(['getJobId' => 'one']);
    $second = fakeJob(['getJobId' => 'two']);

    JobSensor::recordProcessing(new JobProcessing('redis', $first));
    DB::table('test_items')->insert(['name' => 'a']);
    DB::table('test_items')->insert(['name' => 'b']);
    JobSensor::recordProcessed(new JobProcessed('redis', $first));

    JobSensor::recordProcessing(new JobProcessing('redis', $second));
    DB::table('test_items')->insert(['name' => 'c']);
    JobSensor::recordProcessed(new JobProcessed('redis', $second));

    expect($captured[0]['db_query_count'])->toBe(2);
    expect($captured[1]['db_query_count'])->toBe(1);
});

it('attributes queries from nested attempts to both outer and inner', function () {
    config(['observability-log.jobs.channel' => 'test']);

    $captured = [];
    $channel = Mockery::mock();
    $channel->shouldReceive('log')
        ->twice()
        ->andReturnUsing(function ($level, $message, $context) use (&$captured) {
            $captured[] = $context;
        });

    Log::shouldReceive('channel')->with('test')->andReturn($channel);

    $outer = fakeJob(['getJobId' => 'outer']);
    $inner = fakeJob(['getJobId' => 'inner']);

    JobSensor::recordProcessing(new JobProcessing('redis', $outer));
    DB::table('test_items')->insert(['name' => 'pre-inner']);

    JobSensor::recordProcessing(new JobProcessing('redis', $inner));
    DB::table('test_items')->insert(['name' => 'inside-inner-1']);
    DB::table('test_items')->insert(['name' => 'inside-inner-2']);
    JobSensor::recordProcessed(new JobProcessed('redis', $inner));

    DB::table('test_items')->insert(['name' => 'post-inner']);
    JobSensor::recordProcessed(new JobProcessed('redis', $outer));

    expect($captured[0]['job_id'])->toBe('inner');
    expect($captured[0]['db_query_count'])->toBe(2);

    expect($captured[1]['job_id'])->toBe('outer');
    expect($captured[1]['db_query_count'])->toBe(4);
});

it('resets accumulated trait state once all attempts settle', function () {
    config(['observability-log.jobs.channel' => 'test']);

    $channel = Mockery::mock();
    $channel->shouldReceive('log')->twice();
    Log::shouldReceive('channel')->with('test')->andReturn($channel);

    $first = fakeJob(['getJobId' => 'first']);
    $second = fakeJob(['getJobId' => 'second']);

    JobSensor::recordProcessing(new JobProcessing('redis', $first));
    DB::table('test_items')->insert(['name' => 'a']);
    DB::table('test_items')->insert(['name' => 'b']);
    JobSensor::recordProcessed(new JobProcessed('redis', $first));

    $instance = app(JobSensor::class);
    $stats = (function () {
        return [$this->dbQueryCount, count($this->dbSlowQueries)];
    })->call($instance);

    expect($stats[0])->toBe(0);
    expect($stats[1])->toBe(0);

    JobSensor::recordProcessing(new JobProcessing('redis', $second));
    DB::table('test_items')->insert(['name' => 'c']);
    JobSensor::recordProcessed(new JobProcessed('redis', $second));
});

it('does not track queries that fire outside an attempt window', function () {
    config(['observability-log.jobs.channel' => 'test']);

    $captured = null;
    $channel = Mockery::mock();
    $channel->shouldReceive('log')
        ->once()
        ->andReturnUsing(function ($level, $message, $context) use (&$captured) {
            $captured = $context;
        });

    Log::shouldReceive('channel')->with('test')->andReturn($channel);

    $job = fakeJob();

    JobSensor::recordProcessing(new JobProcessing('redis', $job));
    DB::table('test_items')->get();
    JobSensor::recordProcessed(new JobProcessed('redis', $job));

    DB::table('test_items')->get();
    DB::table('test_items')->get();

    expect($captured['db_query_count'])->toBe(1);
});

it('omits db_* fields from attempt entries when collect_queries is disabled even with queries running', function () {
    config([
        'observability-log.jobs.channel' => 'test',
        'observability-log.jobs.collect_queries' => false,
    ]);

    $captured = null;
    $channel = Mockery::mock();
    $channel->shouldReceive('log')
        ->once()
        ->andReturnUsing(function ($level, $message, $context) use (&$captured) {
            $captured = $context;
        });

    Log::shouldReceive('channel')->with('test')->andReturn($channel);

    $job = fakeJob();
    JobSensor::recordProcessing(new JobProcessing('redis', $job));
    DB::table('test_items')->get();
    JobSensor::recordProcessed(new JobProcessed('redis', $job));

    expect($captured)->not->toHaveKey('db_query_count');
    expect($captured)->not->toHaveKey('db_query_total_ms');
    expect($captured)->not->toHaveKey('db_slow_queries');
});
