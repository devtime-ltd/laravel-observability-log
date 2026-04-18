<?php

use DevtimeLtd\LaravelObservabilityLog\LogRequest;
use DevtimeLtd\LaravelObservabilityLog\ObfuscateIp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

describe('request logging', function () {
    beforeEach(function () {
        LogRequest::using(null);
        LogRequest::extend(null);
        LogRequest::message(null);
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
                    && is_int($context['query_count'])
                    && is_float($context['query_total_ms'])
                    && is_float($context['memory_peak_mb']);
            });

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        $middleware = new LogRequest;
        $request = Request::create('/test');
        $middleware->handle($request, fn () => new Response('OK', 200));
    });

    it('does not log when request channel is not configured', function () {
        config(['observability-log.requests.channel' => null]);

        Log::shouldReceive('channel')->never();

        $middleware = new LogRequest;
        $request = Request::create('/test');
        $middleware->handle($request, fn () => new Response('OK', 200));
    });

    it('omits slow_queries key when there are none', function () {
        config(['observability-log.requests.channel' => 'test-channel']);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level, string $message, array $context) => ! array_key_exists('slow_queries', $context));

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        $middleware = new LogRequest;
        $request = Request::create('/test');
        $middleware->handle($request, fn () => new Response('OK', 200));
    });

    it('preserves the response from the next handler', function () {
        config(['observability-log.requests.channel' => null]);

        $middleware = new LogRequest;
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

        $middleware = new LogRequest;
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

        $middleware = new LogRequest;
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

        $middleware = new LogRequest;
        $request = Request::create('/users?page=2', 'POST');
        $middleware->handle($request, fn () => new Response('OK', 200));
    });

    it('does not log when channel is empty string', function () {
        config(['observability-log.requests.channel' => '']);

        Log::shouldReceive('channel')->never();

        $middleware = new LogRequest;
        $request = Request::create('/test');
        $middleware->handle($request, fn () => new Response('OK', 200));
    });

    it('re-throws downstream exceptions', function () {
        config(['observability-log.requests.channel' => 'test-channel']);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')->once();

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        $middleware = new LogRequest;
        $request = Request::create('/test');

        expect(fn () => $middleware->handle($request, fn () => throw new RuntimeException('boom')))
            ->toThrow(RuntimeException::class, 'boom');
    });

    it('uses Log::stack when multiple channels are configured', function () {
        config(['observability-log.requests.channel' => 'channel-a,channel-b']);

        $stack = Mockery::mock();
        $stack->shouldReceive('log')->once()->with('info', 'http.request', Mockery::type('array'));

        Log::shouldReceive('stack')->with(['channel-a', 'channel-b'])->andReturn($stack);

        $middleware = new LogRequest;
        $request = Request::create('/test');
        $middleware->handle($request, fn () => new Response('OK', 200));
    });

    it('returns the response even if logging fails', function () {
        config(['observability-log.requests.channel' => 'broken']);

        Log::shouldReceive('channel')->with('broken')->andThrow(new RuntimeException('log broken'));

        $middleware = new LogRequest;
        $request = Request::create('/test');
        $expected = new Response('OK', 200);

        $response = $middleware->handle($request, fn () => $expected);

        expect($response)->toBe($expected);
    });

    it('re-throws the original exception even if logging fails', function () {
        config(['observability-log.requests.channel' => 'broken']);

        Log::shouldReceive('channel')->with('broken')->andThrow(new RuntimeException('log broken'));

        $middleware = new LogRequest;
        $request = Request::create('/test');

        expect(fn () => $middleware->handle($request, fn () => throw new RuntimeException('original')))
            ->toThrow(RuntimeException::class, 'original');
    });
});

describe('extend callback', function () {
    beforeEach(function () {
        LogRequest::using(null);
        LogRequest::extend(null);
        LogRequest::message(null);
    });

    it('adds fields to the default entry', function () {
        config(['observability-log.requests.channel' => 'test-channel']);

        LogRequest::extend(function (Request $request, ?Response $response, array $entry) {
            $entry['custom'] = 'value';

            return $entry;
        });

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level, string $message, array $context) => $context['custom'] === 'value');

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        $middleware = new LogRequest;
        $request = Request::create('/test');
        $middleware->handle($request, fn () => new Response('OK', 200));
    });

    it('is not applied after extend(null)', function () {
        config(['observability-log.requests.channel' => 'test-channel']);

        LogRequest::extend(function (Request $request, ?Response $response, array $entry) {
            $entry['should_not_exist'] = true;

            return $entry;
        });

        LogRequest::extend(null);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level, string $message, array $context) => ! array_key_exists('should_not_exist', $context));

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        $middleware = new LogRequest;
        $request = Request::create('/test');
        $middleware->handle($request, fn () => new Response('OK', 200));
    });
});

describe('using callback', function () {
    beforeEach(function () {
        LogRequest::using(null);
        LogRequest::extend(null);
        LogRequest::message(null);
    });

    it('replaces the default entry', function () {
        config(['observability-log.requests.channel' => 'test-channel']);

        LogRequest::using(function (Request $request, ?Response $response, array $measurements) {
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

        $middleware = new LogRequest;
        $request = Request::create('/test');
        $middleware->handle($request, fn () => new Response('OK', 200));
    });

    it('composes with extend()', function () {
        config(['observability-log.requests.channel' => 'test-channel']);

        LogRequest::using(fn (Request $request, ?Response $response, array $measurements) => [
            'method' => $request->method(),
        ]);

        LogRequest::extend(function (Request $request, ?Response $response, array $entry) {
            $entry['extra'] = 'value';

            return $entry;
        });

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level, string $message, array $context) => $context['method'] === 'GET' && $context['extra'] === 'value');

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        $middleware = new LogRequest;
        $request = Request::create('/test');
        $middleware->handle($request, fn () => new Response('OK', 200));
    });

    it('receives measurements without query fields when queries disabled', function () {
        config([
            'observability-log.requests.channel' => 'test-channel',
            'observability-log.requests.collect_queries' => false,
        ]);

        LogRequest::using(function (Request $request, ?Response $response, array $measurements) {
            return [
                'has_query_count' => array_key_exists('query_count', $measurements),
                'has_duration' => array_key_exists('duration_ms', $measurements),
            ];
        });

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level, string $message, array $context) => $context['has_query_count'] === false && $context['has_duration'] === true);

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        $middleware = new LogRequest;
        $request = Request::create('/test');
        $middleware->handle($request, fn () => new Response('OK', 200));
    });
});

describe('config options', function () {
    beforeEach(function () {
        LogRequest::using(null);
        LogRequest::extend(null);
        LogRequest::message(null);
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

        $middleware = new LogRequest;
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

        $middleware = new LogRequest;
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

        $middleware = new LogRequest;
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

        $middleware = new LogRequest;
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

        $middleware = new LogRequest;
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
                return ! array_key_exists('query_count', $context)
                    && ! array_key_exists('query_total_ms', $context)
                    && ! array_key_exists('slow_queries', $context)
                    && is_float($context['duration_ms']);
            });

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        $middleware = new LogRequest;
        $request = Request::create('/test');
        $middleware->handle($request, fn () => new Response('OK', 200));
    });
});

describe('message callback', function () {
    beforeEach(function () {
        LogRequest::using(null);
        LogRequest::extend(null);
        LogRequest::message(null);
    });

    it('uses the message callback when set', function () {
        config(['observability-log.requests.channel' => 'test-channel']);

        LogRequest::message(fn (Request $request, ?Response $response) => 'api.request');

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level, string $message, array $context) => $message === 'api.request');

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        $middleware = new LogRequest;
        $request = Request::create('/test');
        $middleware->handle($request, fn () => new Response('OK', 200));
    });

    it('receives request and response in the callback', function () {
        config(['observability-log.requests.channel' => 'test-channel']);

        LogRequest::message(function (Request $request, ?Response $response) {
            return $request->is('api/*') ? 'api.request' : 'web.request';
        });

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level, string $message, array $context) => $message === 'api.request');

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        $middleware = new LogRequest;
        $request = Request::create('/api/users');
        $middleware->handle($request, fn () => new Response('OK', 200));
    });

    it('accepts a string', function () {
        config(['observability-log.requests.channel' => 'test-channel']);

        LogRequest::message('static.message');

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level, string $message, array $context) => $message === 'static.message');

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        $middleware = new LogRequest;
        $request = Request::create('/test');
        $middleware->handle($request, fn () => new Response('OK', 200));
    });

    it('overrides config when callback is set', function () {
        config([
            'observability-log.requests.channel' => 'test-channel',
            'observability-log.requests.message' => 'from.config',
        ]);

        LogRequest::message(fn (Request $request, ?Response $response) => 'from.callback');

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level, string $message, array $context) => $message === 'from.callback');

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        $middleware = new LogRequest;
        $request = Request::create('/test');
        $middleware->handle($request, fn () => new Response('OK', 200));
    });

    it('falls back to config after message(null)', function () {
        config(['observability-log.requests.channel' => 'test-channel']);

        LogRequest::message(fn (Request $request, ?Response $response) => 'custom');
        LogRequest::message(null);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level, string $message, array $context) => $message === 'http.request');

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        $middleware = new LogRequest;
        $request = Request::create('/test');
        $middleware->handle($request, fn () => new Response('OK', 200));
    });
});
