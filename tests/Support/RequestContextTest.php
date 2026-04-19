<?php

use DevtimeLtd\LaravelObservabilityLog\Support\RequestContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;

beforeEach(function () {
    Context::flush();
});

describe('headers()', function () {
    it('returns null for a null request', function () {
        expect(RequestContext::headers(null))->toBeNull();
    });

    it('returns an array keyed by lowercase header names', function () {
        $request = Request::create('/', 'GET', [], [], [], [
            'HTTP_X_TENANT_ID' => 'acme',
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $headers = RequestContext::headers($request);

        expect($headers)->toBeArray()
            ->and(array_key_exists('x-tenant-id', $headers))->toBeTrue()
            ->and(array_key_exists('accept', $headers))->toBeTrue();
    });

    it('masks configured redact keys with [redacted]', function () {
        config(['observability-log.redact_headers' => ['authorization', 'cookie']]);

        $request = Request::create('/', 'GET', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer secret',
            'HTTP_COOKIE' => 'session=xyz',
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $headers = RequestContext::headers($request);

        expect($headers['authorization'])->toBe('[redacted]')
            ->and($headers['cookie'])->toBe('[redacted]')
            ->and($headers['accept'])->toBe('application/json');
    });

    it('matches redact keys case-insensitively', function () {
        config(['observability-log.redact_headers' => ['X-Internal-Secret']]);

        $request = Request::create('/', 'GET', [], [], [], [
            'HTTP_X_INTERNAL_SECRET' => 'hunter2',
        ]);

        $headers = RequestContext::headers($request);

        expect($headers['x-internal-secret'])->toBe('[redacted]');
    });

    it('preserves single-value headers as scalar strings', function () {
        $request = Request::create('/', 'GET', [], [], [], [
            'HTTP_X_TENANT_ID' => 'acme',
        ]);

        $headers = RequestContext::headers($request);

        expect($headers['x-tenant-id'])->toBe('acme');
    });

    it('preserves multi-value headers as arrays', function () {
        $request = Request::create('/', 'GET', [], [], [], []);
        $request->headers->set('X-Multi', ['a', 'b', 'c']);

        $headers = RequestContext::headers($request);

        expect($headers['x-multi'])->toBe(['a', 'b', 'c']);
    });
});

describe('traceId()', function () {
    it('returns null when nothing resolves and no request is passed', function () {
        config(['observability-log.trace_id' => ['X-Request-Id']]);

        expect(RequestContext::traceId(null))->toBeNull();
    });

    it('first-match-wins over the configured header list', function () {
        config(['observability-log.trace_id' => ['X-Primary', 'X-Fallback']]);

        $request = Request::create('/', 'GET', [], [], [], [
            'HTTP_X_FALLBACK' => 'fallback-id',
            'HTTP_X_PRIMARY' => 'primary-id',
        ]);

        expect(RequestContext::traceId($request))->toBe('primary-id');
    });

    it('falls through empty headers to the next in the list', function () {
        config(['observability-log.trace_id' => ['X-Primary', 'X-Fallback']]);

        $request = Request::create('/', 'GET', [], [], [], [
            'HTTP_X_PRIMARY' => '',
            'HTTP_X_FALLBACK' => 'fallback-id',
        ]);

        expect(RequestContext::traceId($request))->toBe('fallback-id');
    });

    it('invokes a callable config with the request and uses its return value', function () {
        $received = null;
        config(['observability-log.trace_id' => function (?Request $request) use (&$received) {
            $received = $request;

            return 'resolved-id';
        }]);

        $request = Request::create('/', 'GET', [], [], [], []);

        expect(RequestContext::traceId($request))->toBe('resolved-id')
            ->and($received)->toBe($request);
    });

    it('callable config that returns null yields null trace id', function () {
        config(['observability-log.trace_id' => fn (?Request $request) => null]);

        expect(RequestContext::traceId(Request::create('/')))->toBeNull();
    });

    it('falls back to Laravel Context when headers do not match', function () {
        config(['observability-log.trace_id' => ['X-Request-Id']]);
        Context::add('trace_id', 'ctx-123');

        $request = Request::create('/', 'GET', [], [], [], []);

        expect(RequestContext::traceId($request))->toBe('ctx-123');
    });

    it('reads Context even when request is null', function () {
        config(['observability-log.trace_id' => ['X-Request-Id']]);
        Context::add('trace_id', 'ctx-456');

        expect(RequestContext::traceId(null))->toBe('ctx-456');
    });

    it('prefers a header match over Context', function () {
        config(['observability-log.trace_id' => ['X-Request-Id']]);
        Context::add('trace_id', 'ctx-fallback');

        $request = Request::create('/', 'GET', [], [], [], [
            'HTTP_X_REQUEST_ID' => 'header-wins',
        ]);

        expect(RequestContext::traceId($request))->toBe('header-wins');
    });

    it('returns null when the configured callable throws', function () {
        config(['observability-log.trace_id' => function (): string {
            throw new RuntimeException('callable broke');
        }]);

        \Illuminate\Support\Facades\Log::shouldReceive('error')
            ->once()
            ->with(\Mockery::pattern('/trace_id callable threw.*callable broke/'));

        expect(RequestContext::traceId(Request::create('/')))->toBeNull();
    });

    it('returns null when the callable returns a non-stringable object', function () {
        $nonStringable = new class {};

        config(['observability-log.trace_id' => fn () => $nonStringable]);

        expect(RequestContext::traceId(Request::create('/')))->toBeNull();
    });

    it('casts stringable objects from the callable', function () {
        $stringable = new class
        {
            public function __toString(): string
            {
                return 'from-stringable';
            }
        };

        config(['observability-log.trace_id' => fn () => $stringable]);

        expect(RequestContext::traceId(Request::create('/')))->toBe('from-stringable');
    });

    it('caps the trace_id at trace_id_max_length bytes', function () {
        config([
            'observability-log.trace_id' => ['X-Request-Id'],
            'observability-log.trace_id_max_length' => 10,
        ]);

        $request = Request::create('/', 'GET', [], [], [], [
            'HTTP_X_REQUEST_ID' => 'this-is-a-very-long-trace-id-value',
        ]);

        expect(RequestContext::traceId($request))->toBe('this-is-a-');
    });

    it('disables trace_id cap when trace_id_max_length is null', function () {
        config([
            'observability-log.trace_id' => ['X-Request-Id'],
            'observability-log.trace_id_max_length' => null,
        ]);

        $request = Request::create('/', 'GET', [], [], [], [
            'HTTP_X_REQUEST_ID' => str_repeat('x', 500),
        ]);

        expect(strlen(RequestContext::traceId($request)))->toBe(500);
    });

    it('caps trace_id values from Context too', function () {
        config([
            'observability-log.trace_id' => ['X-Request-Id'],
            'observability-log.trace_id_max_length' => 5,
        ]);

        Context::add('trace_id', 'context-value-that-is-long');

        expect(RequestContext::traceId(null))->toBe('conte');
    });
});

describe('header value cap', function () {
    it('truncates header values longer than header_value_max_length', function () {
        config(['observability-log.header_value_max_length' => 10]);

        $long = str_repeat('x', 100);

        $request = Request::create('/', 'GET', [], [], [], [
            'HTTP_X_BIG_HEADER' => $long,
        ]);

        $headers = RequestContext::headers($request);

        expect($headers['x-big-header'])->toBe(str_repeat('x', 10));
    });

    it('does not truncate when the value is already short enough', function () {
        config(['observability-log.header_value_max_length' => 1000]);

        $request = Request::create('/', 'GET', [], [], [], [
            'HTTP_X_SHORT' => 'tiny',
        ]);

        $headers = RequestContext::headers($request);

        expect($headers['x-short'])->toBe('tiny');
    });

    it('disables the cap when header_value_max_length is null', function () {
        config(['observability-log.header_value_max_length' => null]);

        $long = str_repeat('y', 20_000);

        $request = Request::create('/', 'GET', [], [], [], [
            'HTTP_X_UNBOUND' => $long,
        ]);

        $headers = RequestContext::headers($request);

        expect(strlen($headers['x-unbound']))->toBe(20_000);
    });

    it('does not truncate redacted headers (the placeholder is short)', function () {
        config([
            'observability-log.header_value_max_length' => 4,
            'observability-log.redact_headers' => ['authorization'],
        ]);

        $request = Request::create('/', 'GET', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer some-very-long-opaque-token-here',
        ]);

        $headers = RequestContext::headers($request);

        expect($headers['authorization'])->toBe('[redacted]');
    });

    it('truncates each value of a multi-value header', function () {
        config(['observability-log.header_value_max_length' => 3]);

        $request = Request::create('/', 'GET', [], [], [], []);
        $request->headers->set('X-Multi', ['firstvalue', 'secondvalue', 'thirdvalue']);

        $headers = RequestContext::headers($request);

        expect($headers['x-multi'])->toBe(['fir', 'sec', 'thi']);
    });
});
