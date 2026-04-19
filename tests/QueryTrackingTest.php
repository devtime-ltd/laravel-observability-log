<?php

use DevtimeLtd\LaravelObservabilityLog\RequestSensor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    RequestSensor::using(null);
    RequestSensor::extend(null);
    RequestSensor::message(null);

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

it('counts database queries', function () {
    config(['observability-log.requests.channel' => 'test-channel']);

    $channel = Mockery::mock();
    $channel->shouldReceive('log')
        ->once()
        ->withArgs(function (string $level, string $message, array $context) {
            return $context['db_query_count'] === 3
                && $context['db_query_total_ms'] > 0;
        });

    Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

    $middleware = new RequestSensor;
    $request = Request::create('/test');
    $middleware->handle($request, function () {
        DB::table('test_items')->insert(['name' => 'one']);
        DB::table('test_items')->insert(['name' => 'two']);
        DB::table('test_items')->get();

        return new Response('OK', 200);
    });
});

it('captures slow queries above threshold', function () {
    config([
        'observability-log.requests.channel' => 'test-channel',
        'observability-log.requests.slow_query_threshold' => 0,
    ]);

    $channel = Mockery::mock();
    $channel->shouldReceive('log')
        ->once()
        ->withArgs(function (string $level, string $message, array $context) {
            return $context['db_query_count'] === 1
                && count($context['db_slow_queries']) === 1
                && is_string($context['db_slow_queries'][0]['sql'])
                && $context['db_slow_queries'][0]['connection'] === 'testing';
        });

    Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

    $middleware = new RequestSensor;
    $request = Request::create('/test');
    $middleware->handle($request, function () {
        DB::table('test_items')->get();

        return new Response('OK', 200);
    });
});

it('does not capture slow queries when threshold is null', function () {
    config([
        'observability-log.requests.channel' => 'test-channel',
        'observability-log.requests.slow_query_threshold' => null,
    ]);

    $channel = Mockery::mock();
    $channel->shouldReceive('log')
        ->once()
        ->withArgs(function (string $level, string $message, array $context) {
            return $context['db_query_count'] === 1
                && ! array_key_exists('db_slow_queries', $context);
        });

    Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

    $middleware = new RequestSensor;
    $request = Request::create('/test');
    $middleware->handle($request, function () {
        DB::table('test_items')->get();

        return new Response('OK', 200);
    });
});

it('does not count queries when collect_queries is disabled', function () {
    config([
        'observability-log.requests.channel' => 'test-channel',
        'observability-log.requests.collect_queries' => false,
    ]);

    $channel = Mockery::mock();
    $channel->shouldReceive('log')
        ->once()
        ->withArgs(function (string $level, string $message, array $context) {
            return ! array_key_exists('db_query_count', $context)
                && ! array_key_exists('db_query_total_ms', $context)
                && ! array_key_exists('db_slow_queries', $context);
        });

    Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

    $middleware = new RequestSensor;
    $request = Request::create('/test');
    $middleware->handle($request, function () {
        DB::table('test_items')->insert(['name' => 'ignored']);
        DB::table('test_items')->get();

        return new Response('OK', 200);
    });
});

it('caps the db_slow_queries array and appends a truncation marker', function () {
    config([
        'observability-log.requests.channel' => 'test-channel',
        'observability-log.requests.slow_query_threshold' => 0,
        'observability-log.requests.slow_queries_max_count' => 2,
    ]);

    $channel = Mockery::mock();
    $channel->shouldReceive('log')
        ->once()
        ->withArgs(function (string $level, string $message, array $context) {
            $slow = $context['db_slow_queries'] ?? null;

            return is_array($slow)
                && count($slow) === 3
                && ($slow[2]['truncated'] ?? null) === '2 more slow queries dropped';
        });

    Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

    $middleware = new RequestSensor;
    $middleware->handle(Request::create('/test'), function () {
        // Threshold is 0, so every query counts as slow. Fire 4 queries
        // against a cap of 2 to confirm 2 real + 1 marker + 2 dropped.
        DB::table('test_items')->get();
        DB::table('test_items')->get();
        DB::table('test_items')->get();
        DB::table('test_items')->get();

        return new Response('OK', 200);
    });
});

it('accepts a numeric string for slow_queries_max_count (env-style config)', function () {
    config([
        'observability-log.requests.channel' => 'test-channel',
        'observability-log.requests.slow_query_threshold' => 0,
        'observability-log.requests.slow_queries_max_count' => '2',
    ]);

    $channel = Mockery::mock();
    $channel->shouldReceive('log')
        ->once()
        ->withArgs(function (string $level, string $message, array $context) {
            $slow = $context['db_slow_queries'] ?? null;

            return is_array($slow)
                && count($slow) === 3
                && ($slow[2]['truncated'] ?? null) === '2 more slow queries dropped';
        });

    Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

    $middleware = new RequestSensor;
    $middleware->handle(Request::create('/test'), function () {
        DB::table('test_items')->get();
        DB::table('test_items')->get();
        DB::table('test_items')->get();
        DB::table('test_items')->get();

        return new Response('OK', 200);
    });
});

it('does not append a truncation marker when slow queries stay under the cap', function () {
    config([
        'observability-log.requests.channel' => 'test-channel',
        'observability-log.requests.slow_query_threshold' => 0,
        'observability-log.requests.slow_queries_max_count' => 50,
    ]);

    $channel = Mockery::mock();
    $channel->shouldReceive('log')
        ->once()
        ->withArgs(function (string $level, string $message, array $context) {
            $slow = $context['db_slow_queries'] ?? [];

            foreach ($slow as $entry) {
                if (array_key_exists('truncated', $entry)) {
                    return false;
                }
            }

            return count($slow) === 2;
        });

    Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

    $middleware = new RequestSensor;
    $middleware->handle(Request::create('/test'), function () {
        DB::table('test_items')->get();
        DB::table('test_items')->get();

        return new Response('OK', 200);
    });
});

it('disables the slow query cap when slow_queries_max_count is null', function () {
    config([
        'observability-log.requests.channel' => 'test-channel',
        'observability-log.requests.slow_query_threshold' => 0,
        'observability-log.requests.slow_queries_max_count' => null,
    ]);

    $channel = Mockery::mock();
    $channel->shouldReceive('log')
        ->once()
        ->withArgs(function (string $level, string $message, array $context) {
            $slow = $context['db_slow_queries'] ?? [];

            foreach ($slow as $entry) {
                if (array_key_exists('truncated', $entry)) {
                    return false;
                }
            }

            return count($slow) === 5;
        });

    Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

    $middleware = new RequestSensor;
    $middleware->handle(Request::create('/test'), function () {
        for ($i = 0; $i < 5; $i++) {
            DB::table('test_items')->get();
        }

        return new Response('OK', 200);
    });
});
