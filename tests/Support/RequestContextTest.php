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

describe('clientIds()', function () {
    it('reads each id from its configured header', function () {
        $request = Request::create('/', 'GET', [], [], [], [
            'HTTP_X_CLIENT_ID' => 'the-browser',
            'HTTP_X_CLIENT_INSTANCE_ID' => 'the-instance',
            'HTTP_X_VISIT_ID' => '0b8f3c52-7a1d-4e69-9c2b-5d1f6a7e8b90',
        ]);

        expect(RequestContext::clientIds($request))->toBe([
            'client_id' => 'the-browser',
            'client_instance_id' => 'the-instance',
            'visit_id' => '0b8f3c52-7a1d-4e69-9c2b-5d1f6a7e8b90',
        ]);
    });

    it('leaves out an id the request did not send', function () {
        $request = Request::create('/', 'GET', [], [], [], ['HTTP_X_VISIT_ID' => 'the-visit']);

        expect(RequestContext::clientIds($request))->toBe(['visit_id' => 'the-visit']);
    });

    it('drops a header that is not a short plain token', function (string $value) {
        $request = Request::create('/', 'GET', [], [], [], [
            'HTTP_X_CLIENT_ID' => $value,
            'HTTP_X_CLIENT_INSTANCE_ID' => $value,
            'HTTP_X_VISIT_ID' => $value,
        ]);

        expect(RequestContext::clientIds($request))->toBe([]);
    })->with([
        'markup' => '<script>alert(1)</script>',
        'spaces' => 'two words',
        'too long' => str_repeat('a', 65),
    ]);

    it('falls back to Context', function () {
        Context::add('client_instance_id', 'from-context');

        expect(RequestContext::clientIds(null))->toBe(['client_instance_id' => 'from-context']);
    });

    it('uses a configured callable', function () {
        config(['observability-log.visit_id' => fn () => 'from-callable']);

        expect(RequestContext::clientIds(null))->toBe(['visit_id' => 'from-callable']);
    });

    it('switches an id off altogether when it is set to null', function () {
        config(['observability-log.client_id' => null]);
        Context::add('client_id', 'left-on-context');

        $request = Request::create('/', 'GET', [], [], [], [
            'HTTP_X_CLIENT_ID' => 'the-browser',
            'HTTP_X_VISIT_ID' => 'the-visit',
        ]);

        expect(RequestContext::clientIds($request))->toBe(['visit_id' => 'the-visit'])
            ->and(RequestContext::enabledClientIds())->toBe(['client_instance_id', 'visit_id']);
    });
});

describe('shareClientIds()', function () {
    it('puts the ids of the request on Context', function () {
        $request = Request::create('/', 'GET', [], [], [], ['HTTP_X_VISIT_ID' => 'the-visit']);

        expect(RequestContext::shareClientIds($request))->toBe(['visit_id' => 'the-visit'])
            ->and(Context::get('visit_id'))->toBe('the-visit')
            ->and(Context::has('client_id'))->toBeFalse();
    });

    it('shares the ids it is handed in place of the request\'s', function () {
        $request = Request::create('/', 'GET', [], [], [], ['HTTP_X_VISIT_ID' => 'the-visit']);

        RequestContext::shareClientIds($request, ['client_instance_id' => 'minted']);

        expect(Context::get('client_instance_id'))->toBe('minted')
            ->and(Context::has('visit_id'))->toBeFalse();
    });

    it('shares nothing for an id that is switched off', function () {
        config(['observability-log.visit_id' => null]);

        expect(RequestContext::shareClientIds(null, ['client_instance_id' => 'minted', 'visit_id' => 'minted']))
            ->toBe(['client_instance_id' => 'minted'])
            ->and(Context::has('visit_id'))->toBeFalse();
    });
});

describe('clientIdHeaders()', function () {
    it('names the first header configured for each id', function () {
        config(['observability-log.visit_id' => ['X-Navigation', 'X-Visit-Id']]);

        expect(RequestContext::clientIdHeaders())->toBe([
            'client_id' => 'X-Client-Id',
            'client_instance_id' => 'X-Client-Instance-Id',
            'visit_id' => 'X-Navigation',
        ]);
    });

    it('names none for an id that comes from a callable or is switched off', function (mixed $config) {
        config(['observability-log.client_id' => $config]);

        expect(RequestContext::clientIdHeaders()['client_id'])->toBeNull();
    })->with([
        'callable' => [[RequestContext::class, 'clientIdHeaders']],
        'null' => [null],
        'empty' => [[]],
    ]);
});
