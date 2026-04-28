<?php

use DevtimeLtd\LaravelObservabilityLog\ObfuscateIp;
use DevtimeLtd\LaravelObservabilityLog\RequestSensor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

describe('request logging', function () {
    beforeEach(function () {
        RequestSensor::using(null);
        RequestSensor::extend(null);
        RequestSensor::message(null);
    });

    it('logs request details to the configured channel', function () {
        config(['observability-log.requests.channel' => 'test-channel']);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(function (string $level, string $message, array $context) {
                return $level === 'info'
                    && $message === 'http.request'
                    && $context['method'] === 'GET'
                    && $context['path'] === 'test'
                    && $context['status'] === 200
                    && is_float($context['duration_ms'])
                    && is_int($context['db_query_count'])
                    && is_float($context['db_query_total_ms'])
                    && is_float($context['memory_peak_mb']);
            });

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        $middleware = new RequestSensor;
        $request = Request::create('/test');
        $middleware->handle($request, fn () => new Response('OK', 200));
    });

    it('does not log when request channel is not configured', function () {
        config(['observability-log.requests.channel' => null]);

        Log::shouldReceive('channel')->never();

        $middleware = new RequestSensor;
        $request = Request::create('/test');
        $middleware->handle($request, fn () => new Response('OK', 200));
    });

    it('omits db_slow_queries key when there are none', function () {
        config(['observability-log.requests.channel' => 'test-channel']);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level, string $message, array $context) => ! array_key_exists('db_slow_queries', $context));

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        $middleware = new RequestSensor;
        $request = Request::create('/test');
        $middleware->handle($request, fn () => new Response('OK', 200));
    });

    it('preserves the response from the next handler', function () {
        config(['observability-log.requests.channel' => null]);

        $middleware = new RequestSensor;
        $request = Request::create('/');
        $expected = new Response('hello', 201);

        $response = $middleware->handle($request, fn () => $expected);

        expect($response)->toBe($expected);
    });

    it('still logs when downstream throws', function () {
        config(['observability-log.requests.channel' => 'test-channel']);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(function (string $level, string $message, array $context) {
                return $message === 'http.request'
                    && $context['status'] === null;
            });

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        $middleware = new RequestSensor;
        $request = Request::create('/test');

        try {
            $middleware->handle($request, fn () => throw new RuntimeException('boom'));
        } catch (RuntimeException) {
        }
    });

    it('measures request duration', function () {
        config(['observability-log.requests.channel' => 'test-channel']);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level, string $message, array $context) => $context['duration_ms'] >= 50);

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        $middleware = new RequestSensor;
        $request = Request::create('/test');
        $middleware->handle($request, function () {
            usleep(50_000); // 50ms

            return new Response('OK', 200);
        });
    });

    it('logs the full url and method', function () {
        config(['observability-log.requests.channel' => 'test-channel']);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(function (string $level, string $message, array $context) {
                return $context['method'] === 'POST'
                    && $context['url'] === 'http://localhost/users?page=2'
                    && $context['path'] === 'users';
            });

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        $middleware = new RequestSensor;
        $request = Request::create('/users?page=2', 'POST');
        $middleware->handle($request, fn () => new Response('OK', 200));
    });

    it('does not log when channel is empty string', function () {
        config(['observability-log.requests.channel' => '']);

        Log::shouldReceive('channel')->never();

        $middleware = new RequestSensor;
        $request = Request::create('/test');
        $middleware->handle($request, fn () => new Response('OK', 200));
    });

    it('re-throws downstream exceptions', function () {
        config(['observability-log.requests.channel' => 'test-channel']);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')->once();

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        $middleware = new RequestSensor;
        $request = Request::create('/test');

        expect(fn () => $middleware->handle($request, fn () => throw new RuntimeException('boom')))
            ->toThrow(RuntimeException::class, 'boom');
    });

    it('uses Log::stack when multiple channels are configured', function () {
        config(['observability-log.requests.channel' => 'channel-a,channel-b']);

        $stack = Mockery::mock();
        $stack->shouldReceive('log')->once()->with('info', 'http.request', Mockery::type('array'));

        Log::shouldReceive('stack')->with(['channel-a', 'channel-b'])->andReturn($stack);

        $middleware = new RequestSensor;
        $request = Request::create('/test');
        $middleware->handle($request, fn () => new Response('OK', 200));
    });

    it('trims whitespace and drops empty entries in the channel list', function () {
        config(['observability-log.requests.channel' => 'channel-a, channel-b ,']);

        $stack = Mockery::mock();
        $stack->shouldReceive('log')->once();

        Log::shouldReceive('stack')->with(['channel-a', 'channel-b'])->andReturn($stack);

        $middleware = new RequestSensor;
        $middleware->handle(Request::create('/test'), fn () => new Response('OK', 200));
    });

    it('uses Log::channel when the comma-separated list normalises to one entry', function () {
        config(['observability-log.requests.channel' => 'channel-a,']);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')->once();

        Log::shouldReceive('channel')->with('channel-a')->andReturn($channel);

        $middleware = new RequestSensor;
        $middleware->handle(Request::create('/test'), fn () => new Response('OK', 200));
    });

    it('does not log when the channel list normalises to nothing', function () {
        // Channel config is truthy (non-empty string) but becomes [] after
        // trimming and filtering. Should no-op rather than throw.
        config(['observability-log.requests.channel' => ' , ,']);

        Log::shouldReceive('channel')->never();
        Log::shouldReceive('stack')->never();

        $middleware = new RequestSensor;
        $middleware->handle(Request::create('/test'), fn () => new Response('OK', 200));
    });

    it('returns the response even if logging fails', function () {
        config(['observability-log.requests.channel' => 'broken']);

        Log::shouldReceive('channel')->with('broken')->andThrow(new RuntimeException('log broken'));

        $middleware = new RequestSensor;
        $request = Request::create('/test');
        $expected = new Response('OK', 200);

        $response = $middleware->handle($request, fn () => $expected);

        expect($response)->toBe($expected);
    });

    it('re-throws the original exception even if logging fails', function () {
        config(['observability-log.requests.channel' => 'broken']);

        Log::shouldReceive('channel')->with('broken')->andThrow(new RuntimeException('log broken'));

        $middleware = new RequestSensor;
        $request = Request::create('/test');

        expect(fn () => $middleware->handle($request, fn () => throw new RuntimeException('original')))
            ->toThrow(RuntimeException::class, 'original');
    });
});

describe('promoted top-level fields', function () {
    beforeEach(function () {
        RequestSensor::using(null);
        RequestSensor::extend(null);
        RequestSensor::message(null);
    });

    it('includes scheme and host', function () {
        config(['observability-log.requests.channel' => 'test-channel']);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(function (string $level, string $message, array $context) {
                return $context['scheme'] === 'http' && $context['host'] === 'localhost';
            });

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        (new RequestSensor)->handle(Request::create('/test'), fn () => new Response('OK', 200));
    });

    it('reflects HTTPS scheme when the request is secure', function () {
        config(['observability-log.requests.channel' => 'test-channel']);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level, string $message, array $context) => $context['scheme'] === 'https');

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        (new RequestSensor)->handle(Request::create('https://example.com/test'), fn () => new Response('OK', 200));
    });

    it('includes query_string when present', function () {
        config(['observability-log.requests.channel' => 'test-channel']);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level, string $message, array $context) => ($context['query_string'] ?? null) === 'page=2&sort=asc');

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        (new RequestSensor)->handle(Request::create('/users?page=2&sort=asc'), fn () => new Response('OK', 200));
    });

    it('omits query_string when the URL has none', function () {
        config(['observability-log.requests.channel' => 'test-channel']);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level, string $message, array $context) => ! array_key_exists('query_string', $context));

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        (new RequestSensor)->handle(Request::create('/users'), fn () => new Response('OK', 200));
    });

    it('keeps user_agent and referer as top-level fields', function () {
        config(['observability-log.requests.channel' => 'test-channel']);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(function (string $level, string $message, array $context) {
                return $context['user_agent'] === 'tests/1.0'
                    && $context['referer'] === 'https://example.com/';
            });

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        $request = Request::create('/test', 'GET', [], [], [], [
            'HTTP_USER_AGENT' => 'tests/1.0',
            'HTTP_REFERER' => 'https://example.com/',
        ]);

        (new RequestSensor)->handle($request, fn () => new Response('OK', 200));
    });
});

describe('header capture', function () {
    beforeEach(function () {
        RequestSensor::using(null);
        RequestSensor::extend(null);
        RequestSensor::message(null);
        Context::flush();
    });

    it('omits headers by default', function () {
        config(['observability-log.requests.channel' => 'test-channel']);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level, string $message, array $context) => ! array_key_exists('headers', $context));

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        (new RequestSensor)->handle(Request::create('/test'), fn () => new Response('OK', 200));
    });

    it('includes headers when capture_headers is enabled', function () {
        config([
            'observability-log.requests.channel' => 'test-channel',
            'observability-log.requests.capture_headers' => true,
        ]);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(function (string $level, string $message, array $context) {
                return isset($context['headers'])
                    && ($context['headers']['x-tenant-id'] ?? null) === 'acme';
            });

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        $request = Request::create('/test', 'GET', [], [], [], [
            'HTTP_X_TENANT_ID' => 'acme',
        ]);

        (new RequestSensor)->handle($request, fn () => new Response('OK', 200));
    });

    it('redacts sensitive headers with the default redact list', function () {
        config([
            'observability-log.requests.channel' => 'test-channel',
            'observability-log.requests.capture_headers' => true,
        ]);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(function (string $level, string $message, array $context) {
                return ($context['headers']['authorization'] ?? null) === '[redacted]'
                    && ($context['headers']['cookie'] ?? null) === '[redacted]';
            });

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        $request = Request::create('/test', 'GET', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer secret-token',
            'HTTP_COOKIE' => 'session=xyz',
        ]);

        (new RequestSensor)->handle($request, fn () => new Response('OK', 200));
    });

    it('honours additions to the redact_headers config (case-insensitive)', function () {
        config([
            'observability-log.requests.channel' => 'test-channel',
            'observability-log.requests.capture_headers' => true,
            'observability-log.redact_headers' => ['authorization', 'X-Internal-Signing-Key'],
        ]);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(function (string $level, string $message, array $context) {
                return ($context['headers']['x-internal-signing-key'] ?? null) === '[redacted]';
            });

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        $request = Request::create('/test', 'GET', [], [], [], [
            'HTTP_X_INTERNAL_SIGNING_KEY' => 'super-secret',
        ]);

        (new RequestSensor)->handle($request, fn () => new Response('OK', 200));
    });
});

describe('trace_id', function () {
    beforeEach(function () {
        RequestSensor::using(null);
        RequestSensor::extend(null);
        RequestSensor::message(null);
        Context::flush();
    });

    it('is emitted when X-Request-Id is present', function () {
        config(['observability-log.requests.channel' => 'test-channel']);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level, string $message, array $context) => ($context['trace_id'] ?? null) === 'abc-123');

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        $request = Request::create('/test', 'GET', [], [], [], [
            'HTTP_X_REQUEST_ID' => 'abc-123',
        ]);

        (new RequestSensor)->handle($request, fn () => new Response('OK', 200));
    });

    it('is omitted when no header matches and Context is empty', function () {
        config(['observability-log.requests.channel' => 'test-channel']);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level, string $message, array $context) => ! array_key_exists('trace_id', $context));

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        (new RequestSensor)->handle(Request::create('/test'), fn () => new Response('OK', 200));
    });

    it('falls back to Laravel Context when no header matches', function () {
        config(['observability-log.requests.channel' => 'test-channel']);
        Context::add('trace_id', 'ctx-999');

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level, string $message, array $context) => ($context['trace_id'] ?? null) === 'ctx-999');

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        (new RequestSensor)->handle(Request::create('/test'), fn () => new Response('OK', 200));
    });

    it('prefers configured header over Context', function () {
        config(['observability-log.requests.channel' => 'test-channel']);
        Context::add('trace_id', 'ctx-999');

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level, string $message, array $context) => ($context['trace_id'] ?? null) === 'hdr-111');

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        $request = Request::create('/test', 'GET', [], [], [], [
            'HTTP_X_REQUEST_ID' => 'hdr-111',
        ]);

        (new RequestSensor)->handle($request, fn () => new Response('OK', 200));
    });
});

describe('extend callback', function () {
    beforeEach(function () {
        RequestSensor::using(null);
        RequestSensor::extend(null);
        RequestSensor::message(null);
    });

    it('adds fields to the default entry', function () {
        config(['observability-log.requests.channel' => 'test-channel']);

        RequestSensor::extend(function (Request $request, ?Response $response, array $entry) {
            $entry['custom'] = 'value';

            return $entry;
        });

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level, string $message, array $context) => $context['custom'] === 'value');

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        $middleware = new RequestSensor;
        $request = Request::create('/test');
        $middleware->handle($request, fn () => new Response('OK', 200));
    });

    it('is not applied after extend(null)', function () {
        config(['observability-log.requests.channel' => 'test-channel']);

        RequestSensor::extend(function (Request $request, ?Response $response, array $entry) {
            $entry['should_not_exist'] = true;

            return $entry;
        });

        RequestSensor::extend(null);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level, string $message, array $context) => ! array_key_exists('should_not_exist', $context));

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        $middleware = new RequestSensor;
        $request = Request::create('/test');
        $middleware->handle($request, fn () => new Response('OK', 200));
    });
});

describe('using callback', function () {
    beforeEach(function () {
        RequestSensor::using(null);
        RequestSensor::extend(null);
        RequestSensor::message(null);
    });

    it('replaces the default entry', function () {
        config(['observability-log.requests.channel' => 'test-channel']);

        RequestSensor::using(function (Request $request, ?Response $response, array $measurements) {
            return [
                'custom_method' => $request->method(),
                'custom_duration' => $measurements['duration_ms'],
            ];
        });

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(function (string $level, string $message, array $context) {
                return $context['custom_method'] === 'GET'
                    && is_float($context['custom_duration'])
                    && ! array_key_exists('url', $context);
            });

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        $middleware = new RequestSensor;
        $request = Request::create('/test');
        $middleware->handle($request, fn () => new Response('OK', 200));
    });

    it('composes with extend()', function () {
        config(['observability-log.requests.channel' => 'test-channel']);

        RequestSensor::using(fn (Request $request, ?Response $response, array $measurements) => [
            'method' => $request->method(),
        ]);

        RequestSensor::extend(function (Request $request, ?Response $response, array $entry) {
            $entry['extra'] = 'value';

            return $entry;
        });

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level, string $message, array $context) => $context['method'] === 'GET' && $context['extra'] === 'value');

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        $middleware = new RequestSensor;
        $request = Request::create('/test');
        $middleware->handle($request, fn () => new Response('OK', 200));
    });

    it('receives measurements without query fields when queries disabled', function () {
        config([
            'observability-log.requests.channel' => 'test-channel',
            'observability-log.requests.collect_queries' => false,
        ]);

        RequestSensor::using(function (Request $request, ?Response $response, array $measurements) {
            return [
                'has_query_count' => array_key_exists('db_query_count', $measurements),
                'has_duration' => array_key_exists('duration_ms', $measurements),
            ];
        });

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level, string $message, array $context) => $context['has_query_count'] === false && $context['has_duration'] === true);

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        $middleware = new RequestSensor;
        $request = Request::create('/test');
        $middleware->handle($request, fn () => new Response('OK', 200));
    });
});

describe('config options', function () {
    beforeEach(function () {
        RequestSensor::using(null);
        RequestSensor::extend(null);
        RequestSensor::message(null);
    });

    it('masks IP when obfuscate_ip is a callable', function () {
        config([
            'observability-log.requests.channel' => 'test-channel',
            'observability-log.requests.obfuscate_ip' => ObfuscateIp::level(1),
        ]);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level, string $message, array $context) => $context['ip'] === '127.0.0.0');

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        $middleware = new RequestSensor;
        $request = Request::create('/test');
        $middleware->handle($request, fn () => new Response('OK', 200));
    });

    it('supports custom IP masking callables', function () {
        config([
            'observability-log.requests.channel' => 'test-channel',
            'observability-log.requests.obfuscate_ip' => fn (?string $ip) => 'redacted',
        ]);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level, string $message, array $context) => $context['ip'] === 'redacted');

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        $middleware = new RequestSensor;
        $request = Request::create('/test');
        $middleware->handle($request, fn () => new Response('OK', 200));
    });

    it('uses message from config', function () {
        config([
            'observability-log.requests.channel' => 'test-channel',
            'observability-log.requests.message' => 'custom.request',
        ]);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level, string $message, array $context) => $message === 'custom.request');

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        $middleware = new RequestSensor;
        $request = Request::create('/test');
        $middleware->handle($request, fn () => new Response('OK', 200));
    });

    it('uses level from config', function () {
        config([
            'observability-log.requests.channel' => 'test-channel',
            'observability-log.requests.level' => 'debug',
        ]);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level, string $message, array $context) => $level === 'debug');

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        $middleware = new RequestSensor;
        $request = Request::create('/test');
        $middleware->handle($request, fn () => new Response('OK', 200));
    });

    it('reports invalid log level to default channel', function () {
        config([
            'observability-log.requests.channel' => 'test-channel',
            'observability-log.requests.level' => 'warnn',
        ]);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->andThrow(new \Psr\Log\InvalidArgumentException('Level "warnn" is not defined'));

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);
        Log::shouldReceive('error')->once()->with(\Mockery::pattern('/warnn/'));

        $middleware = new RequestSensor;
        $request = Request::create('/test');
        $response = $middleware->handle($request, fn () => new Response('OK', 200));

        expect($response->getStatusCode())->toBe(200);
    });

    it('omits query fields when collect_queries is disabled', function () {
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
                    && ! array_key_exists('db_slow_queries', $context)
                    && is_float($context['duration_ms']);
            });

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        $middleware = new RequestSensor;
        $request = Request::create('/test');
        $middleware->handle($request, fn () => new Response('OK', 200));
    });
});

describe('callback error handling', function () {
    beforeEach(function () {
        RequestSensor::using(null);
        RequestSensor::extend(null);
        RequestSensor::message(null);
    });

    it('falls back to the default entry when the using callback throws', function () {
        config(['observability-log.requests.channel' => 'test-channel']);

        RequestSensor::using(function () {
            throw new RuntimeException('using broke');
        });

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level, string $message, array $context) => $context['method'] === 'GET' && $context['path'] === 'test');

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);
        Log::shouldReceive('error')->once()->with(Mockery::pattern('/using callback threw.*using broke/'));

        $middleware = new RequestSensor;
        $middleware->handle(Request::create('/test'), fn () => new Response('OK', 200));
    });

    it('keeps the default entry when the extend callback throws', function () {
        config(['observability-log.requests.channel' => 'test-channel']);

        RequestSensor::extend(function () {
            throw new RuntimeException('extend broke');
        });

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level, string $message, array $context) => $context['method'] === 'GET');

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);
        Log::shouldReceive('error')->once()->with(Mockery::pattern('/extend callback threw.*extend broke/'));

        $middleware = new RequestSensor;
        $middleware->handle(Request::create('/test'), fn () => new Response('OK', 200));
    });

    it('uses the config message when the message callback throws', function () {
        config(['observability-log.requests.channel' => 'test-channel']);

        RequestSensor::message(function () {
            throw new RuntimeException('message broke');
        });

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level, string $message, array $context) => $message === 'http.request');

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);
        Log::shouldReceive('error')->once()->with(Mockery::pattern('/message callback threw.*message broke/'));

        $middleware = new RequestSensor;
        $middleware->handle(Request::create('/test'), fn () => new Response('OK', 200));
    });
});

describe('message callback', function () {
    beforeEach(function () {
        RequestSensor::using(null);
        RequestSensor::extend(null);
        RequestSensor::message(null);
    });

    it('uses the message callback when set', function () {
        config(['observability-log.requests.channel' => 'test-channel']);

        RequestSensor::message(fn (Request $request, ?Response $response) => 'api.request');

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level, string $message, array $context) => $message === 'api.request');

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        $middleware = new RequestSensor;
        $request = Request::create('/test');
        $middleware->handle($request, fn () => new Response('OK', 200));
    });

    it('receives request and response in the callback', function () {
        config(['observability-log.requests.channel' => 'test-channel']);

        RequestSensor::message(function (Request $request, ?Response $response) {
            return $request->is('api/*') ? 'api.request' : 'web.request';
        });

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level, string $message, array $context) => $message === 'api.request');

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        $middleware = new RequestSensor;
        $request = Request::create('/api/users');
        $middleware->handle($request, fn () => new Response('OK', 200));
    });

    it('accepts a string', function () {
        config(['observability-log.requests.channel' => 'test-channel']);

        RequestSensor::message('static.message');

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level, string $message, array $context) => $message === 'static.message');

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        $middleware = new RequestSensor;
        $request = Request::create('/test');
        $middleware->handle($request, fn () => new Response('OK', 200));
    });

    it('overrides config when callback is set', function () {
        config([
            'observability-log.requests.channel' => 'test-channel',
            'observability-log.requests.message' => 'from.config',
        ]);

        RequestSensor::message(fn (Request $request, ?Response $response) => 'from.callback');

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level, string $message, array $context) => $message === 'from.callback');

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        $middleware = new RequestSensor;
        $request = Request::create('/test');
        $middleware->handle($request, fn () => new Response('OK', 200));
    });

    it('falls back to config after message(null)', function () {
        config(['observability-log.requests.channel' => 'test-channel']);

        RequestSensor::message(fn (Request $request, ?Response $response) => 'custom');
        RequestSensor::message(null);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level, string $message, array $context) => $message === 'http.request');

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        $middleware = new RequestSensor;
        $request = Request::create('/test');
        $middleware->handle($request, fn () => new Response('OK', 200));
    });
});
