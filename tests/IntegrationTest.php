<?php

namespace DevtimeLtd\LaravelObservabilityLog\Tests;

use DevtimeLtd\LaravelObservabilityLog\LogRequest;
use DevtimeLtd\LaravelObservabilityLog\ObfuscateIp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Monolog\Handler\TestHandler;

class IntegrationTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('observability-log.requests.channel', 'test-observability');
        $app['config']->set('logging.channels.test-observability', [
            'driver' => 'monolog',
            'handler' => TestHandler::class,
        ]);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
    }

    protected function defineRoutes($router): void
    {
        $router->middleware(LogRequest::class)->group(function ($router) {
            $router->get('/hello', fn () => response()->json(['message' => 'hello']));

            $router->get('/users', function () {
                return response()->json(DB::table('users')->get());
            });

            $router->get('/users/{id}/posts/{post}', function (string $id, string $post) {
                return response()->json(['user' => $id, 'post' => $post]);
            });

            $router->get('/error', function () {
                throw new \RuntimeException('boom');
            });
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        LogRequest::using(null);
        LogRequest::extend(null);

        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
        });

        DB::table('users')->insert(['name' => 'Alice']);
    }

    private function loggedRecord(): \Monolog\LogRecord
    {
        $handler = app('log')->channel('test-observability')->getLogger()->getHandlers()[0];

        return $handler->getRecords()[0];
    }

    private function loggedEntry(): array
    {
        return $this->loggedRecord()->context;
    }

    public function test_logs_a_successful_request(): void
    {
        $this->get('/hello')->assertOk();

        $entry = $this->loggedEntry();

        $this->assertSame('GET', $entry['method']);
        $this->assertSame('hello', $entry['path']);
        $this->assertSame(200, $entry['status']);
        $this->assertIsFloat($entry['duration_ms']);
        $this->assertIsFloat($entry['memory_peak_mb']);
    }

    public function test_logs_response_metadata(): void
    {
        $this->get('/hello', ['Referer' => 'https://example.com'])->assertOk();

        $entry = $this->loggedEntry();

        $this->assertSame('https://example.com', $entry['referer']);
        $this->assertStringContainsString('application/json', $entry['content_type']);
        $this->assertIsInt($entry['response_size']);
        $this->assertGreaterThan(0, $entry['response_size']);
    }

    public function test_logs_route_params(): void
    {
        $this->get('/users/42/posts/7')->assertOk();

        $entry = $this->loggedEntry();

        $this->assertSame(['id' => '42', 'post' => '7'], $entry['route_params']);
    }

    public function test_tracks_database_queries(): void
    {
        $this->get('/users')->assertOk();

        $entry = $this->loggedEntry();

        $this->assertSame(1, $entry['query_count']);
        $this->assertIsFloat($entry['query_total_ms']);
    }

    public function test_logs_error_status_when_route_throws(): void
    {
        $this->get('/error')->assertStatus(500);

        $entry = $this->loggedEntry();

        $this->assertSame('GET', $entry['method']);
        $this->assertSame(500, $entry['status']);
    }

    public function test_obfuscates_ip(): void
    {
        config(['observability-log.requests.obfuscate_ip' => ObfuscateIp::level(1)]);

        $this->get('/hello')->assertOk();

        $entry = $this->loggedEntry();

        $this->assertSame('127.0.0.0', $entry['ip']);
    }

    public function test_extend_adds_fields(): void
    {
        LogRequest::extend(function ($request, $response, $entry) {
            $entry['custom'] = 'value';

            return $entry;
        });

        $this->get('/hello')->assertOk();

        $entry = $this->loggedEntry();

        $this->assertSame('value', $entry['custom']);
    }

    public function test_using_replaces_default_entry(): void
    {
        LogRequest::using(function ($request, $response, $measurements) {
            return [
                'only_this' => true,
                'duration' => $measurements['duration_ms'],
            ];
        });

        $this->get('/hello')->assertOk();

        $entry = $this->loggedEntry();

        $this->assertTrue($entry['only_this']);
        $this->assertIsFloat($entry['duration']);
        $this->assertArrayNotHasKey('method', $entry);
    }

    public function test_default_message_is_http_request(): void
    {
        $this->get('/hello')->assertOk();

        $this->assertSame('http.request', $this->loggedRecord()->message);
    }

    public function test_message_is_configurable(): void
    {
        config(['observability-log.requests.message' => 'custom.request']);

        $this->get('/hello')->assertOk();

        $this->assertSame('custom.request', $this->loggedRecord()->message);
    }

    public function test_message_callback_overrides_config(): void
    {
        LogRequest::message(fn ($request, $response) => 'callback.request');

        $this->get('/hello')->assertOk();

        $this->assertSame('callback.request', $this->loggedRecord()->message);
    }

    public function test_level_is_configurable(): void
    {
        config(['observability-log.requests.level' => 'debug']);

        $this->get('/hello')->assertOk();

        $this->assertSame('debug', $this->loggedRecord()->level->toPsrLogLevel());
    }
}
