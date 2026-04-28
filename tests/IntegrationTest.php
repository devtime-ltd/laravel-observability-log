<?php

namespace DevtimeLtd\LaravelObservabilityLog\Tests;

use DevtimeLtd\LaravelObservabilityLog\ExceptionSensor;
use DevtimeLtd\LaravelObservabilityLog\JobSensor;
use DevtimeLtd\LaravelObservabilityLog\ObfuscateIp;
use DevtimeLtd\LaravelObservabilityLog\RequestSensor;
use DevtimeLtd\LaravelObservabilityLog\Tests\Fixtures\IntegrationTestJob;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;

class IntegrationTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('observability-log.requests.channel', 'test-observability');
        $app['config']->set('observability-log.exceptions.channel', 'test-observability');
        $app['config']->set('observability-log.jobs.channel', 'test-observability');
        $app['config']->set('logging.channels.test-observability', [
            'driver' => 'monolog',
            'handler' => TestHandler::class,
        ]);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        $app['config']->set('queue.default', 'sync');
    }

    protected function defineRoutes($router): void
    {
        $router->middleware(RequestSensor::class)->group(function ($router) {
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

        RequestSensor::using(null);
        RequestSensor::extend(null);
        RequestSensor::message(null);
        ExceptionSensor::using(null);
        ExceptionSensor::extend(null);
        ExceptionSensor::message(null);
        JobSensor::using(null);
        JobSensor::extend(null);
        JobSensor::message(null);
        $this->app->forgetInstance(JobSensor::class);
        Context::flush();

        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
        });

        DB::table('users')->insert(['name' => 'Alice']);
    }

    private function records(): array
    {
        $handler = app('log')->channel('test-observability')->getLogger()->getHandlers()[0];

        return $handler->getRecords();
    }

    private function loggedRecord(int $index = 0): LogRecord
    {
        return $this->records()[$index];
    }

    private function loggedEntry(int $index = 0): array
    {
        return $this->loggedRecord($index)->context;
    }

    private function recordWithMessage(string $message): ?LogRecord
    {
        foreach ($this->records() as $record) {
            if ($record->message === $message) {
                return $record;
            }
        }

        return null;
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

        $this->assertSame(1, $entry['db_query_count']);
        $this->assertIsFloat($entry['db_query_total_ms']);
    }

    public function test_logs_error_status_when_route_throws(): void
    {
        $this->get('/error')->assertStatus(500);

        $record = $this->recordWithMessage('http.request');
        $this->assertNotNull($record);
        $this->assertSame('GET', $record->context['method']);
        $this->assertSame(500, $record->context['status']);
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
        RequestSensor::extend(function ($request, $response, $entry) {
            $entry['custom'] = 'value';

            return $entry;
        });

        $this->get('/hello')->assertOk();

        $entry = $this->loggedEntry();

        $this->assertSame('value', $entry['custom']);
    }

    public function test_using_replaces_default_entry(): void
    {
        RequestSensor::using(function ($request, $response, $measurements) {
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
        RequestSensor::message(fn ($request, $response) => 'callback.request');

        $this->get('/hello')->assertOk();

        $this->assertSame('callback.request', $this->loggedRecord()->message);
    }

    public function test_level_is_configurable(): void
    {
        config(['observability-log.requests.level' => 'debug']);

        $this->get('/hello')->assertOk();

        $this->assertSame('debug', $this->loggedRecord()->level->toPsrLogLevel());
    }

    public function test_promoted_top_level_fields(): void
    {
        $this->get('/users/42/posts/7?include=comments')->assertOk();

        $entry = $this->loggedEntry();

        $this->assertSame('http', $entry['scheme']);
        $this->assertSame('localhost', $entry['host']);
        $this->assertSame('include=comments', $entry['query_string']);
        $this->assertArrayHasKey('action', $entry);
        $this->assertIsString($entry['action']);
    }

    public function test_captures_headers_with_redaction_when_enabled(): void
    {
        config(['observability-log.requests.capture_headers' => true]);

        $this->get('/hello', [
            'Authorization' => 'Bearer secret-token',
            'X-Tenant-Id' => 'acme',
        ])->assertOk();

        $entry = $this->loggedEntry();

        $this->assertArrayHasKey('headers', $entry);
        $this->assertSame('[redacted]', $entry['headers']['authorization']);
        $this->assertSame('acme', $entry['headers']['x-tenant-id']);
    }

    public function test_headers_omitted_by_default(): void
    {
        $this->get('/hello')->assertOk();

        $entry = $this->loggedEntry();

        $this->assertArrayNotHasKey('headers', $entry);
    }

    public function test_trace_id_from_request_header(): void
    {
        $this->get('/hello', ['X-Request-Id' => 'abc-123'])->assertOk();

        $entry = $this->loggedEntry();

        $this->assertSame('abc-123', $entry['trace_id']);
    }

    public function test_trace_id_falls_back_to_context(): void
    {
        Context::add('trace_id', 'ctx-999');

        $this->get('/hello')->assertOk();

        $entry = $this->loggedEntry();

        $this->assertSame('ctx-999', $entry['trace_id']);
    }

    public function test_exception_sensor_reports_via_real_handler(): void
    {
        $handler = app(ExceptionHandler::class);
        $handler->report(new \RuntimeException('boom'));

        $record = $this->recordWithMessage('error.exception');

        $this->assertNotNull($record);
        $this->assertSame('error', $record->level->toPsrLogLevel());
        $this->assertSame(\RuntimeException::class, $record->context['class']);
        $this->assertSame('boom', $record->context['message']);
        $this->assertIsString($record->context['trace']);

        // HTTP kernel has not been resolved in this test path, so the
        // entry should not include request context fields.
        $this->assertArrayNotHasKey('method', $record->context);
        $this->assertArrayNotHasKey('url', $record->context);
        $this->assertArrayNotHasKey('headers', $record->context);
    }

    public function test_exception_ignore_list_prevents_logging(): void
    {
        config(['observability-log.exceptions.ignore' => [\RuntimeException::class]]);

        $handler = app(ExceptionHandler::class);
        $handler->report(new \RuntimeException('boom'));

        $this->assertNull($this->recordWithMessage('error.exception'));
    }

    public function test_error_route_produces_both_request_and_exception_records_sharing_trace_id(): void
    {
        $this->get('/error', ['X-Request-Id' => 'shared-42'])->assertStatus(500);

        $requestRecord = $this->recordWithMessage('http.request');
        $exceptionRecord = $this->recordWithMessage('error.exception');

        $this->assertNotNull($requestRecord);
        $this->assertNotNull($exceptionRecord);
        $this->assertSame('shared-42', $requestRecord->context['trace_id']);
        $this->assertSame('shared-42', $exceptionRecord->context['trace_id']);
    }

    public function test_trace_args_config_produces_structured_trace(): void
    {
        config(['observability-log.exceptions.trace_args' => true]);

        $handler = app(ExceptionHandler::class);
        $handler->report(new \RuntimeException('boom'));

        $record = $this->recordWithMessage('error.exception');

        $this->assertNotNull($record);
        $this->assertIsArray($record->context['trace']);
    }

    public function test_trace_max_bytes_truncates_long_traces(): void
    {
        config(['observability-log.exceptions.trace_max_bytes' => 50]);

        $handler = app(ExceptionHandler::class);
        $handler->report(new \RuntimeException('boom'));

        $record = $this->recordWithMessage('error.exception');

        $this->assertNotNull($record);
        $this->assertStringContainsString('[truncated at 50 bytes]', $record->context['trace']);
    }

    public function test_db_query_listener_registers_exactly_once_across_requests(): void
    {
        // Simulate Octane-style repeated requests on the same app. The
        // DB listener must not accumulate: each query should be counted
        // once per request, not N times after N requests.
        $this->get('/users')->assertOk();
        $this->get('/users')->assertOk();
        $this->get('/users')->assertOk();

        $records = array_values(array_filter(
            $this->records(),
            fn ($record) => $record->message === 'http.request'
        ));

        $this->assertCount(3, $records);

        foreach ($records as $record) {
            $this->assertSame(1, $record->context['db_query_count']);
        }
    }

    public function test_exception_in_testbench_http_test_includes_request_context(): void
    {
        // HTTP kernel is resolved by $this->get, so exception entries
        // triggered within the request lifecycle should include request
        // fields even though runningInConsole() is true in phpunit.
        $this->get('/error', ['X-Request-Id' => 'req-ok'])->assertStatus(500);

        $exception = $this->recordWithMessage('error.exception');

        $this->assertNotNull($exception);
        $this->assertArrayHasKey('method', $exception->context);
        $this->assertSame('GET', $exception->context['method']);
        $this->assertArrayNotHasKey('command', $exception->context);
    }

    public function test_db_fields_omitted_when_collect_queries_disabled(): void
    {
        config(['observability-log.requests.collect_queries' => false]);

        $this->get('/users')->assertOk();

        $entry = $this->loggedEntry();

        $this->assertArrayNotHasKey('db_query_count', $entry);
        $this->assertArrayNotHasKey('db_query_total_ms', $entry);
        $this->assertArrayNotHasKey('db_slow_queries', $entry);
    }

    public function test_trace_id_is_capped_at_configured_length(): void
    {
        config(['observability-log.trace_id_max_length' => 8]);

        $this->get('/hello', ['X-Request-Id' => 'this-is-a-very-long-trace-id'])->assertOk();

        $entry = $this->loggedEntry();

        $this->assertSame('this-is-', $entry['trace_id']);
    }

    public function test_trace_args_frames_are_capped(): void
    {
        config([
            'observability-log.exceptions.trace_args' => true,
            'observability-log.exceptions.trace_args_max_frames' => 1,
        ]);

        $handler = app(ExceptionHandler::class);
        $handler->report(new \RuntimeException('boom'));

        $record = $this->recordWithMessage('error.exception');

        $this->assertNotNull($record);
        $this->assertIsArray($record->context['trace']);
        $trace = $record->context['trace'];
        // 1 frame + the truncation marker
        $this->assertCount(2, $trace);
        $this->assertArrayHasKey('truncated', end($trace));
    }

    public function test_job_sensor_logs_attempt_when_sync_job_is_dispatched(): void
    {
        Queue::push(new IntegrationTestJob);

        $record = $this->recordWithMessage('job.attempt');

        $this->assertNotNull($record);
        $this->assertSame('processed', $record->context['status']);
        $this->assertSame('sync', $record->context['connection']);
        $this->assertIsFloat($record->context['duration_ms']);
        $this->assertArrayNotHasKey('exception', $record->context);
    }

    public function test_job_sensor_tracks_db_queries_during_sync_job(): void
    {
        Queue::push(new IntegrationTestJob(runQuery: true));

        $record = $this->recordWithMessage('job.attempt');

        $this->assertNotNull($record);
        $this->assertSame(1, $record->context['db_query_count']);
        $this->assertIsFloat($record->context['db_query_total_ms']);
    }

    public function test_job_sensor_logs_queued_event_via_dispatched_event(): void
    {
        Event::dispatch(new JobQueued('redis', 'default', 'job-77', new \stdClass, '{}', null));

        $record = $this->recordWithMessage('job.queued');

        $this->assertNotNull($record);
        $this->assertSame('stdClass', $record->context['class']);
        $this->assertSame('default', $record->context['queue']);
        $this->assertSame('redis', $record->context['connection']);
        $this->assertSame('job-77', $record->context['job_id']);
    }

    public function test_job_sensor_failed_event_emits_failure_status(): void
    {
        $exception = null;

        try {
            Queue::push(new Fixtures\ThrowingJob);
        } catch (\Throwable $e) {
            $exception = $e;
        }

        $this->assertNotNull($exception, 'Sync queue should have re-thrown the job exception');

        $record = $this->recordWithMessage('job.attempt');

        $this->assertNotNull($record);
        $this->assertSame('failed', $record->context['status']);
        $this->assertSame(\RuntimeException::class, $record->context['exception']['class']);
        $this->assertSame('boom from job', $record->context['exception']['message']);
    }

    public function test_request_and_job_share_trace_id_when_context_is_set_during_request(): void
    {
        $this->app['router']->middleware(RequestSensor::class)->get('/dispatch-job', function () {
            Context::add('trace_id', 'shared-job-tid');
            Queue::push(new IntegrationTestJob);

            return response()->json(['ok' => true]);
        });

        $this->get('/dispatch-job')->assertOk();

        $requestRecord = $this->recordWithMessage('http.request');
        $jobRecord = $this->recordWithMessage('job.attempt');

        $this->assertNotNull($requestRecord);
        $this->assertNotNull($jobRecord);
        $this->assertSame('shared-job-tid', $requestRecord->context['trace_id']);
        $this->assertSame('shared-job-tid', $jobRecord->context['trace_id']);
    }

    public function test_job_sensor_disabled_when_jobs_channel_is_unset(): void
    {
        config(['observability-log.jobs.channel' => null]);

        Queue::push(new IntegrationTestJob);

        $this->assertNull($this->recordWithMessage('job.attempt'));
    }

    public function test_job_query_listener_registers_exactly_once_across_attempts(): void
    {
        Queue::push(new IntegrationTestJob(runQuery: true));
        Queue::push(new IntegrationTestJob(runQuery: true));
        Queue::push(new IntegrationTestJob(runQuery: true));

        $records = array_values(array_filter(
            $this->records(),
            fn ($record) => $record->message === 'job.attempt'
        ));

        $this->assertCount(3, $records);

        foreach ($records as $record) {
            $this->assertSame(1, $record->context['db_query_count']);
        }
    }
}
