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
    app()->forgetInstance(JobSensor::QUERY_LISTENER_BINDING);

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

it('does not register the DB listener when collect_queries is disabled', function () {
    config([
        'observability-log.jobs.channel' => 'test',
        'observability-log.jobs.collect_queries' => false,
    ]);

    Log::shouldReceive('channel')->with('test')->andReturn(
        Mockery::mock()->shouldReceive('log')->getMock()
    );

    JobSensor::recordProcessing(new JobProcessing('redis', fakeJob()));

    expect(app()->bound(JobSensor::QUERY_LISTENER_BINDING))->toBeFalse();
});
