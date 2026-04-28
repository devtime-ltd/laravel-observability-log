<?php

use DevtimeLtd\LaravelObservabilityLog\CommandSensor;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

beforeEach(function () {
    CommandSensor::using(null);
    CommandSensor::extend(null);
    CommandSensor::message(null);
    Context::flush();
    app()->forgetInstance(CommandSensor::class);

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

it('counts database queries during a command', function () {
    config(['observability-log.commands.channel' => 'test']);

    $captured = null;
    $channel = Mockery::mock();
    $channel->shouldReceive('log')
        ->once()
        ->andReturnUsing(function ($level, $message, $context) use (&$captured) {
            $captured = $context;
        });

    Log::shouldReceive('channel')->with('test')->andReturn($channel);

    $input = new ArrayInput([]);
    CommandSensor::recordStarting(new CommandStarting('migrate', $input, new NullOutput));

    DB::table('test_items')->insert(['name' => 'one']);
    DB::table('test_items')->insert(['name' => 'two']);
    DB::table('test_items')->get();

    CommandSensor::recordFinished(new CommandFinished('migrate', $input, new NullOutput, 0));

    expect($captured['db_query_count'])->toBe(3);
    expect($captured['db_query_total_ms'])->toBeFloat();
});

it('captures slow queries above the configured threshold', function () {
    config([
        'observability-log.commands.channel' => 'test',
        'observability-log.commands.db_slow_query_threshold' => 0,
    ]);

    $captured = null;
    $channel = Mockery::mock();
    $channel->shouldReceive('log')
        ->once()
        ->andReturnUsing(function ($level, $message, $context) use (&$captured) {
            $captured = $context;
        });

    Log::shouldReceive('channel')->with('test')->andReturn($channel);

    $input = new ArrayInput([]);
    CommandSensor::recordStarting(new CommandStarting('migrate', $input, new NullOutput));
    DB::table('test_items')->get();
    CommandSensor::recordFinished(new CommandFinished('migrate', $input, new NullOutput, 0));

    expect($captured['db_slow_queries'])->toHaveCount(1);
    expect($captured['db_slow_queries'][0]['connection'])->toBe('testing');
});

it('attributes nested Artisan::call queries to both outer and inner commands', function () {
    config(['observability-log.commands.channel' => 'test']);

    $captured = [];
    $channel = Mockery::mock();
    $channel->shouldReceive('log')
        ->twice()
        ->andReturnUsing(function ($level, $message, $context) use (&$captured) {
            $captured[] = $context;
        });

    Log::shouldReceive('channel')->with('test')->andReturn($channel);

    $outerInput = new ArrayInput([]);
    $innerInput = new ArrayInput([]);

    CommandSensor::recordStarting(new CommandStarting('outer', $outerInput, new NullOutput));
    DB::table('test_items')->insert(['name' => 'pre-inner']);

    CommandSensor::recordStarting(new CommandStarting('inner', $innerInput, new NullOutput));
    DB::table('test_items')->insert(['name' => 'inside-inner-1']);
    DB::table('test_items')->insert(['name' => 'inside-inner-2']);
    CommandSensor::recordFinished(new CommandFinished('inner', $innerInput, new NullOutput, 0));

    DB::table('test_items')->insert(['name' => 'post-inner']);
    CommandSensor::recordFinished(new CommandFinished('outer', $outerInput, new NullOutput, 0));

    expect($captured[0]['command'])->toBe('inner');
    expect($captured[0]['db_query_count'])->toBe(2);

    expect($captured[1]['command'])->toBe('outer');
    expect($captured[1]['db_query_count'])->toBe(4);
});

it('inherits the top-level db_collect_queries default when the commands section omits it', function () {
    config([
        'observability-log.commands.channel' => 'test',
        'observability-log.db_collect_queries' => false,
    ]);

    $captured = null;
    $channel = Mockery::mock();
    $channel->shouldReceive('log')
        ->once()
        ->andReturnUsing(function ($level, $message, $context) use (&$captured) {
            $captured = $context;
        });

    Log::shouldReceive('channel')->with('test')->andReturn($channel);

    $input = new ArrayInput([]);
    CommandSensor::recordStarting(new CommandStarting('migrate', $input, new NullOutput));
    DB::table('test_items')->get();
    CommandSensor::recordFinished(new CommandFinished('migrate', $input, new NullOutput, 0));

    expect($captured)->not->toHaveKey('db_query_count');
});

it('lets a sensor-level db_collect_queries override the top-level default', function () {
    config([
        'observability-log.commands.channel' => 'test',
        'observability-log.db_collect_queries' => true,
        'observability-log.commands.db_collect_queries' => false,
    ]);

    $captured = null;
    $channel = Mockery::mock();
    $channel->shouldReceive('log')
        ->once()
        ->andReturnUsing(function ($level, $message, $context) use (&$captured) {
            $captured = $context;
        });

    Log::shouldReceive('channel')->with('test')->andReturn($channel);

    $input = new ArrayInput([]);
    CommandSensor::recordStarting(new CommandStarting('migrate', $input, new NullOutput));
    DB::table('test_items')->get();
    CommandSensor::recordFinished(new CommandFinished('migrate', $input, new NullOutput, 0));

    expect($captured)->not->toHaveKey('db_query_count');
});
