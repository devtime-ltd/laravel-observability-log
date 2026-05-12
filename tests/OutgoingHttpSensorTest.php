<?php

use DevtimeLtd\LaravelObservabilityLog\OutgoingHttpSensor;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

function clientRequest(string $method, string $url): ClientRequest
{
    return new ClientRequest(new PsrRequest($method, $url));
}

function clientResponse(int $status, string $body = ''): ClientResponse
{
    return new ClientResponse(new PsrResponse($status, [], $body));
}

beforeEach(function () {
    OutgoingHttpSensor::using(null);
    OutgoingHttpSensor::extend(null);
    OutgoingHttpSensor::message(null);
    Context::flush();
    app()->forgetInstance(OutgoingHttpSensor::class);
});

describe('channel resolution', function () {
    it('does not log when channel is null', function () {
        config(['observability-log.outgoing_http.channel' => null]);
        Log::shouldReceive('channel')->never();
        Log::shouldReceive('stack')->never();

        $request = clientRequest('GET', 'https://api.example.com/users');
        OutgoingHttpSensor::recordSending(new RequestSending($request));
        OutgoingHttpSensor::recordReceived(new ResponseReceived($request, clientResponse(200)));
    });

    it('uses Log::stack when multiple channels are configured', function () {
        config(['observability-log.outgoing_http.channel' => 'a,b']);

        $stack = Mockery::mock();
        $stack->shouldReceive('log')->once()->with('info', 'http.outgoing', Mockery::type('array'));
        Log::shouldReceive('stack')->with(['a', 'b'])->andReturn($stack);

        $request = clientRequest('GET', 'https://api.example.com/users');
        OutgoingHttpSensor::recordSending(new RequestSending($request));
        OutgoingHttpSensor::recordReceived(new ResponseReceived($request, clientResponse(200)));
    });
});

describe('response received', function () {
    it('emits method, url (no query), host, path, status, response_size, duration_ms', function () {
        config(['observability-log.outgoing_http.channel' => 'test']);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(function (string $level, string $message, array $context) {
                return $level === 'info'
                    && $message === 'http.outgoing'
                    && $context['method'] === 'GET'
                    && $context['url'] === 'https://api.example.com/users'
                    && $context['host'] === 'api.example.com'
                    && $context['path'] === '/users'
                    && $context['status'] === 200
                    && $context['response_size'] === 11
                    && is_float($context['duration_ms']);
            });
        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        $request = clientRequest('GET', 'https://api.example.com/users?token=secret');
        OutgoingHttpSensor::recordSending(new RequestSending($request));
        OutgoingHttpSensor::recordReceived(new ResponseReceived($request, clientResponse(200, 'hello world')));
    });

    it('uses failed_level for 5xx responses', function () {
        config(['observability-log.outgoing_http.channel' => 'test']);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn ($level, $message, $context) => $level === 'error' && $context['status'] === 503);
        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        $request = clientRequest('GET', 'https://api.example.com/users');
        OutgoingHttpSensor::recordSending(new RequestSending($request));
        OutgoingHttpSensor::recordReceived(new ResponseReceived($request, clientResponse(503)));
    });

    it('keeps 4xx on the regular level', function () {
        config(['observability-log.outgoing_http.channel' => 'test']);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn ($level, $message, $context) => $level === 'info' && $context['status'] === 404);
        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        $request = clientRequest('GET', 'https://api.example.com/missing');
        OutgoingHttpSensor::recordSending(new RequestSending($request));
        OutgoingHttpSensor::recordReceived(new ResponseReceived($request, clientResponse(404)));
    });

    it('skips emission when no RequestSending fired first', function () {
        config(['observability-log.outgoing_http.channel' => 'test']);
        Log::shouldReceive('channel')->never();

        $request = clientRequest('GET', 'https://api.example.com/users');
        OutgoingHttpSensor::recordReceived(new ResponseReceived($request, clientResponse(200)));
    });

    it('matches Sending and Received by request instance, not URL', function () {
        config(['observability-log.outgoing_http.channel' => 'test']);

        $captured = [];
        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->twice()
            ->andReturnUsing(function ($level, $message, $context) use (&$captured) {
                $captured[] = $context;
            });
        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        $a = clientRequest('GET', 'https://api.example.com/users');
        $b = clientRequest('GET', 'https://api.example.com/users');

        OutgoingHttpSensor::recordSending(new RequestSending($a));
        OutgoingHttpSensor::recordSending(new RequestSending($b));
        OutgoingHttpSensor::recordReceived(new ResponseReceived($b, clientResponse(200)));
        OutgoingHttpSensor::recordReceived(new ResponseReceived($a, clientResponse(500)));

        expect($captured[0]['status'])->toBe(200);
        expect($captured[1]['status'])->toBe(500);
    });
});

describe('query string handling', function () {
    it('strips query string from url by default', function () {
        config(['observability-log.outgoing_http.channel' => 'test']);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(function ($level, $message, $context) {
                return $context['url'] === 'https://api.example.com/users'
                    && ! array_key_exists('query_string', $context);
            });
        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        $request = clientRequest('GET', 'https://api.example.com/users?token=secret&page=2');
        OutgoingHttpSensor::recordSending(new RequestSending($request));
        OutgoingHttpSensor::recordReceived(new ResponseReceived($request, clientResponse(200)));
    });

    it('includes the query string when capture_query_string is true', function () {
        config([
            'observability-log.outgoing_http.channel' => 'test',
            'observability-log.outgoing_http.capture_query_string' => true,
        ]);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(function ($level, $message, $context) {
                return $context['url'] === 'https://api.example.com/users?token=secret&page=2'
                    && $context['query_string'] === 'token=secret&page=2';
            });
        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        $request = clientRequest('GET', 'https://api.example.com/users?token=secret&page=2');
        OutgoingHttpSensor::recordSending(new RequestSending($request));
        OutgoingHttpSensor::recordReceived(new ResponseReceived($request, clientResponse(200)));
    });

    it('does not emit query_string when capture_query_string is true but URL has no query', function () {
        config([
            'observability-log.outgoing_http.channel' => 'test',
            'observability-log.outgoing_http.capture_query_string' => true,
        ]);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn ($level, $message, $context) => ! array_key_exists('query_string', $context));
        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        $request = clientRequest('GET', 'https://api.example.com/users');
        OutgoingHttpSensor::recordSending(new RequestSending($request));
        OutgoingHttpSensor::recordReceived(new ResponseReceived($request, clientResponse(200)));
    });
});

describe('connection failed', function () {
    it('emits exception, no status, at failed_level', function () {
        config(['observability-log.outgoing_http.channel' => 'test']);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(function ($level, $message, $context) {
                return $level === 'error'
                    && $context['method'] === 'GET'
                    && $context['url'] === 'https://api.example.com/users'
                    && $context['host'] === 'api.example.com'
                    && ! array_key_exists('status', $context)
                    && $context['exception']['class'] === ConnectionException::class
                    && $context['exception']['message'] === 'connection refused'
                    && is_float($context['duration_ms']);
            });
        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        $request = clientRequest('GET', 'https://api.example.com/users');
        OutgoingHttpSensor::recordSending(new RequestSending($request));
        OutgoingHttpSensor::recordConnectionFailed(new ConnectionFailed($request, new ConnectionException('connection refused')));
    });

    it('still emits when no RequestSending fired first, without duration_ms', function () {
        config(['observability-log.outgoing_http.channel' => 'test']);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(function ($level, $message, $context) {
                return $context['exception']['class'] === ConnectionException::class
                    && ! array_key_exists('duration_ms', $context);
            });
        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        $request = clientRequest('GET', 'https://api.example.com/users');
        OutgoingHttpSensor::recordConnectionFailed(new ConnectionFailed($request, new ConnectionException('dns failure')));
    });
});

describe('failures_only', function () {
    it('skips emission for successful responses when failures_only is true', function () {
        config([
            'observability-log.outgoing_http.channel' => 'test',
            'observability-log.outgoing_http.failures_only' => true,
        ]);
        Log::shouldReceive('channel')->never();

        $request = clientRequest('GET', 'https://api.example.com/users');
        OutgoingHttpSensor::recordSending(new RequestSending($request));
        OutgoingHttpSensor::recordReceived(new ResponseReceived($request, clientResponse(200)));
    });

    it('still emits 5xx responses when failures_only is true', function () {
        config([
            'observability-log.outgoing_http.channel' => 'test',
            'observability-log.outgoing_http.failures_only' => true,
        ]);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn ($level, $message, $context) => $level === 'error' && $context['status'] === 502);
        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        $request = clientRequest('GET', 'https://api.example.com/users');
        OutgoingHttpSensor::recordSending(new RequestSending($request));
        OutgoingHttpSensor::recordReceived(new ResponseReceived($request, clientResponse(502)));
    });

    it('still emits ConnectionFailed entries when failures_only is true', function () {
        config([
            'observability-log.outgoing_http.channel' => 'test',
            'observability-log.outgoing_http.failures_only' => true,
        ]);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn ($level, $message, $context) => $level === 'error' && isset($context['exception']));
        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        $request = clientRequest('GET', 'https://api.example.com/users');
        OutgoingHttpSensor::recordSending(new RequestSending($request));
        OutgoingHttpSensor::recordConnectionFailed(new ConnectionFailed($request, new ConnectionException('refused')));
    });

    it('inherits the top-level failures_only default', function () {
        config([
            'observability-log.outgoing_http.channel' => 'test',
            'observability-log.failures_only' => true,
        ]);
        Log::shouldReceive('channel')->never();

        $request = clientRequest('GET', 'https://api.example.com/users');
        OutgoingHttpSensor::recordSending(new RequestSending($request));
        OutgoingHttpSensor::recordReceived(new ResponseReceived($request, clientResponse(200)));
    });

    it('skips 4xx responses when failures_only is true', function () {
        config([
            'observability-log.outgoing_http.channel' => 'test',
            'observability-log.outgoing_http.failures_only' => true,
        ]);
        Log::shouldReceive('channel')->never();

        $request = clientRequest('GET', 'https://api.example.com/missing');
        OutgoingHttpSensor::recordSending(new RequestSending($request));
        OutgoingHttpSensor::recordReceived(new ResponseReceived($request, clientResponse(404)));
    });
});

describe('capture_headers', function () {
    it('omits headers by default', function () {
        config(['observability-log.outgoing_http.channel' => 'test']);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn ($level, $message, $context) => ! array_key_exists('headers', $context));
        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        $request = new ClientRequest(new PsrRequest('GET', 'https://api.example.com/users', ['Accept' => 'application/json']));
        OutgoingHttpSensor::recordSending(new RequestSending($request));
        OutgoingHttpSensor::recordReceived(new ResponseReceived($request, clientResponse(200)));
    });

    it('emits headers when capture_headers is true, with sensitive values redacted', function () {
        config([
            'observability-log.outgoing_http.channel' => 'test',
            'observability-log.outgoing_http.capture_headers' => true,
        ]);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(function ($level, $message, $context) {
                return isset($context['headers'])
                    && $context['headers']['accept'] === 'application/json'
                    && $context['headers']['authorization'] === '[redacted]'
                    && $context['headers']['x-api-key'] === '[redacted]';
            });
        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        $request = new ClientRequest(new PsrRequest('GET', 'https://api.example.com/users', [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer sk-12345',
            'X-API-Key' => 'real-key',
        ]));
        OutgoingHttpSensor::recordSending(new RequestSending($request));
        OutgoingHttpSensor::recordReceived(new ResponseReceived($request, clientResponse(200)));
    });

    it('inherits the top-level capture_headers default', function () {
        config([
            'observability-log.outgoing_http.channel' => 'test',
            'observability-log.capture_headers' => true,
        ]);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn ($level, $message, $context) => isset($context['headers']));
        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        $request = new ClientRequest(new PsrRequest('GET', 'https://api.example.com/users', ['Accept' => 'application/json']));
        OutgoingHttpSensor::recordSending(new RequestSending($request));
        OutgoingHttpSensor::recordReceived(new ResponseReceived($request, clientResponse(200)));
    });

    it('emits headers on ConnectionFailed entries when capture_headers is true', function () {
        config([
            'observability-log.outgoing_http.channel' => 'test',
            'observability-log.outgoing_http.capture_headers' => true,
        ]);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn ($level, $message, $context) => isset($context['headers']) && isset($context['exception']));
        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        $request = new ClientRequest(new PsrRequest('GET', 'https://api.example.com/users', ['Accept' => 'application/json']));
        OutgoingHttpSensor::recordSending(new RequestSending($request));
        OutgoingHttpSensor::recordConnectionFailed(new ConnectionFailed($request, new ConnectionException('refused')));
    });
});

describe('ignore_hosts', function () {
    it('skips emission when host matches the ignore list', function () {
        config([
            'observability-log.outgoing_http.channel' => 'test',
            'observability-log.outgoing_http.ignore_hosts' => ['login.example.com'],
        ]);
        Log::shouldReceive('channel')->never();

        $request = clientRequest('POST', 'https://login.example.com/oauth/token');
        OutgoingHttpSensor::recordSending(new RequestSending($request));
        OutgoingHttpSensor::recordReceived(new ResponseReceived($request, clientResponse(200)));
    });

    it('matches host case-insensitively', function () {
        config([
            'observability-log.outgoing_http.channel' => 'test',
            'observability-log.outgoing_http.ignore_hosts' => ['Login.Example.COM'],
        ]);
        Log::shouldReceive('channel')->never();

        $request = clientRequest('GET', 'https://login.example.com/me');
        OutgoingHttpSensor::recordSending(new RequestSending($request));
        OutgoingHttpSensor::recordReceived(new ResponseReceived($request, clientResponse(200)));
    });

    it('still logs hosts not in the ignore list', function () {
        config([
            'observability-log.outgoing_http.channel' => 'test',
            'observability-log.outgoing_http.ignore_hosts' => ['login.example.com'],
        ]);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')->once();
        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        $request = clientRequest('GET', 'https://api.example.com/users');
        OutgoingHttpSensor::recordSending(new RequestSending($request));
        OutgoingHttpSensor::recordReceived(new ResponseReceived($request, clientResponse(200)));
    });
});

describe('trace_id', function () {
    it('is emitted from Context when set', function () {
        config(['observability-log.outgoing_http.channel' => 'test']);
        Context::add('trace_id', 'http-tid');

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn ($level, $message, $context) => ($context['trace_id'] ?? null) === 'http-tid');
        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        $request = clientRequest('GET', 'https://api.example.com/users');
        OutgoingHttpSensor::recordSending(new RequestSending($request));
        OutgoingHttpSensor::recordReceived(new ResponseReceived($request, clientResponse(200)));
    });

    it('is omitted when Context is empty', function () {
        config(['observability-log.outgoing_http.channel' => 'test']);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn ($level, $message, $context) => ! array_key_exists('trace_id', $context));
        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        $request = clientRequest('GET', 'https://api.example.com/users');
        OutgoingHttpSensor::recordSending(new RequestSending($request));
        OutgoingHttpSensor::recordReceived(new ResponseReceived($request, clientResponse(200)));
    });
});

describe('callbacks', function () {
    it('using replaces the default entry', function () {
        config(['observability-log.outgoing_http.channel' => 'test']);

        OutgoingHttpSensor::using(function ($event, array $measurements) {
            return [
                'only_this' => true,
                'duration' => $measurements['duration_ms'],
            ];
        });

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(function ($level, $message, $context) {
                return $context['only_this'] === true
                    && is_float($context['duration'])
                    && ! array_key_exists('method', $context);
            });
        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        $request = clientRequest('GET', 'https://api.example.com/users');
        OutgoingHttpSensor::recordSending(new RequestSending($request));
        OutgoingHttpSensor::recordReceived(new ResponseReceived($request, clientResponse(200)));
    });

    it('extend adds fields after using runs', function () {
        config(['observability-log.outgoing_http.channel' => 'test']);

        OutgoingHttpSensor::using(fn ($event, $measurements) => ['base' => true]);
        OutgoingHttpSensor::extend(function ($event, array $entry) {
            $entry['added'] = 'yes';

            return $entry;
        });

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn ($level, $message, $context) => $context['base'] === true && $context['added'] === 'yes');
        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        $request = clientRequest('GET', 'https://api.example.com/users');
        OutgoingHttpSensor::recordSending(new RequestSending($request));
        OutgoingHttpSensor::recordReceived(new ResponseReceived($request, clientResponse(200)));
    });

    it('using falls back to the default entry when callback throws', function () {
        config(['observability-log.outgoing_http.channel' => 'test']);

        OutgoingHttpSensor::using(function () {
            throw new RuntimeException('using broke');
        });

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn ($level, $message, $context) => ($context['method'] ?? null) === 'GET');
        Log::shouldReceive('channel')->with('test')->andReturn($channel);
        Log::shouldReceive('error')->once()->with(Mockery::pattern('/using callback threw.*using broke/'));

        $request = clientRequest('GET', 'https://api.example.com/users');
        OutgoingHttpSensor::recordSending(new RequestSending($request));
        OutgoingHttpSensor::recordReceived(new ResponseReceived($request, clientResponse(200)));
    });

    it('message can be a closure', function () {
        config(['observability-log.outgoing_http.channel' => 'test']);

        OutgoingHttpSensor::message(function ($event) {
            $host = parse_url($event->request->url(), PHP_URL_HOST);

            return 'http.outgoing.'.$host;
        });

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn ($level, $message, $context) => $message === 'http.outgoing.api.example.com');
        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        $request = clientRequest('GET', 'https://api.example.com/users');
        OutgoingHttpSensor::recordSending(new RequestSending($request));
        OutgoingHttpSensor::recordReceived(new ResponseReceived($request, clientResponse(200)));
    });
});

describe('error handling', function () {
    it('swallows logger errors and clears in-flight state', function () {
        config(['observability-log.outgoing_http.channel' => 'broken']);

        Log::shouldReceive('channel')->with('broken')->andThrow(new RuntimeException('log broken'));
        Log::shouldReceive('error')->atLeast()->once();

        $request = clientRequest('GET', 'https://api.example.com/users');
        OutgoingHttpSensor::recordSending(new RequestSending($request));
        OutgoingHttpSensor::recordReceived(new ResponseReceived($request, clientResponse(200)));

        $instance = app(OutgoingHttpSensor::class);
        expect((function () {
            return $this->requests;
        })->call($instance))->toBe([]);
    });
});
