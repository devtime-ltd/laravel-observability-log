<?php

use DevtimeLtd\LaravelObservabilityLog\LogRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    LogRequest::using(null);
    LogRequest::extend(null);
    LogRequest::message(null);

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
            return $context['query_count'] === 3
                && $context['query_total_ms'] > 0;
        });

    Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

    $middleware = new LogRequest;
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
            return $context['query_count'] === 1
                && count($context['slow_queries']) === 1
                && is_string($context['slow_queries'][0]['sql'])
                && $context['slow_queries'][0]['connection'] === 'testing';
        });

    Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

    $middleware = new LogRequest;
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
            return $context['query_count'] === 1
                && ! array_key_exists('slow_queries', $context);
        });

    Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

    $middleware = new LogRequest;
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
            return ! array_key_exists('query_count', $context)
                && ! array_key_exists('query_total_ms', $context)
                && ! array_key_exists('slow_queries', $context);
        });

    Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

    $middleware = new LogRequest;
    $request = Request::create('/test');
    $middleware->handle($request, function () {
        DB::table('test_items')->insert(['name' => 'ignored']);
        DB::table('test_items')->get();

        return new Response('OK', 200);
    });
});
