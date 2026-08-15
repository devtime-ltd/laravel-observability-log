<?php

use DevtimeLtd\LaravelObservabilityLog\ClientSensor;
use DevtimeLtd\LaravelObservabilityLog\ObfuscateIp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

function browserRequest(array $server = []): Request
{
    return Request::create('/_observability', 'POST', [], [], [], array_merge([
        'HTTP_USER_AGENT' => 'Mozilla/5.0',
        'REMOTE_ADDR' => '203.0.113.9',
    ], $server));
}

/** Captures the single entry the sensor emits, or null when it emits none. */
function recordClient(array $payload, ?callable $before = null): ?array
{
    config(['observability-log.client.channel' => 'test-channel']);

    if ($before) {
        $before();
    }

    $captured = null;

    $channel = Mockery::mock();
    $channel->shouldReceive('log')->andReturnUsing(function ($level, $message, $context) use (&$captured) {
        $captured = compact('level', 'message', 'context');
    });

    Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

    ClientSensor::record(browserRequest(), $payload);

    return $captured;
}

describe('client logging', function () {
    beforeEach(function () {
        ClientSensor::using(null);
        ClientSensor::extend(null);
        ClientSensor::message(null);
    });

    it('emits nothing when no channel is configured', function () {
        config(['observability-log.client.channel' => null]);

        Log::shouldReceive('channel')->never();
        Log::shouldReceive('stack')->never();

        ClientSensor::record(browserRequest(), ['kind' => 'error', 'message' => 'boom']);
    });

    it('logs an error at the failure level with the browser context', function () {
        $entry = recordClient([
            'kind' => 'error',
            'message' => 'boom',
            'type' => 'TypeError',
            'source' => 'https://example.test/app.js',
            'line' => 42,
            'col' => 7,
            'stack' => "at foo\nat bar",
            'url' => 'https://example.test/pricing?plan=pro',
            'referrer' => 'https://example.test/',
            'viewport' => ['w' => 1280, 'h' => 800],
            'page_id' => 'abc123',
            'trace_id' => 'trace-from-the-page',
        ]);

        expect($entry['level'])->toBe('error')
            ->and($entry['message'])->toBe('client.error')
            ->and($entry['context']['kind'])->toBe('error')
            ->and($entry['context']['error_message'])->toBe('boom')
            ->and($entry['context']['error_type'])->toBe('TypeError')
            ->and($entry['context']['line'])->toBe(42)
            ->and($entry['context']['handled'])->toBeFalse()
            ->and($entry['context']['host'])->toBe('example.test')
            ->and($entry['context']['path'])->toBe('/pricing')
            ->and($entry['context']['viewport'])->toBe('1280x800')
            ->and($entry['context']['page_id'])->toBe('abc123')
            ->and($entry['context']['user_agent'])->toBe('Mozilla/5.0')
            ->and($entry['context']['ip'])->toBe('203.0.113.9');
    });

    it('prefers the trace id of the page over the beacon request', function () {
        config(['observability-log.trace_id' => ['X-Trace-Id']]);

        $entry = recordClient([
            'kind' => 'error',
            'message' => 'boom',
            'trace_id' => 'trace-from-the-page',
        ]);

        expect($entry['context']['trace_id'])->toBe('trace-from-the-page');
    });

    it('falls back to the beacon request trace id', function () {
        $entry = recordClient(['kind' => 'error', 'message' => 'boom'], function () {
            config(['observability-log.trace_id' => fn () => 'trace-from-the-beacon']);
        });

        expect($entry['context']['trace_id'])->toBe('trace-from-the-beacon');
    });

    it('logs a vital at the routine level with a rating', function () {
        $entry = recordClient(['kind' => 'vital', 'name' => 'LCP', 'value' => 4200]);

        expect($entry['level'])->toBe('info')
            ->and($entry['message'])->toBe('client.vital')
            ->and($entry['context']['vital'])->toBe('LCP')
            ->and($entry['context']['value'])->toBe(4200.0)
            ->and($entry['context']['rating'])->toBeNull();
    });

    it('keeps a rating the browser sent when it is one we know', function () {
        $entry = recordClient(['kind' => 'vital', 'name' => 'CLS', 'value' => 0.04, 'rating' => 'good']);

        expect($entry['context']['rating'])->toBe('good');
    });

    it('drops a vital it does not recognise', function () {
        Log::shouldReceive('channel')->never();
        config(['observability-log.client.channel' => 'test-channel']);

        ClientSensor::record(browserRequest(), ['kind' => 'vital', 'name' => 'MADE_UP', 'value' => 1]);
    });

    it('drops a vital with a non-numeric value', function () {
        Log::shouldReceive('channel')->never();
        config(['observability-log.client.channel' => 'test-channel']);

        ClientSensor::record(browserRequest(), ['kind' => 'vital', 'name' => 'LCP', 'value' => 'fast']);
    });

    it('logs an allowlisted event', function () {
        $entry = recordClient(['kind' => 'event', 'name' => 'copy', 'props' => ['field' => 'countryCode']], function () {
            config(['observability-log.client.events' => ['copy']]);
        });

        expect($entry['message'])->toBe('client.event')
            ->and($entry['context']['event'])->toBe('copy')
            ->and($entry['context']['props'])->toBe(['field' => 'countryCode']);
    });

    it('drops an event that is not on the allowlist', function () {
        config([
            'observability-log.client.channel' => 'test-channel',
            'observability-log.client.events' => ['copy'],
        ]);

        Log::shouldReceive('channel')->never();

        ClientSensor::record(browserRequest(), ['kind' => 'event', 'name' => 'not-allowed']);
    });

    it('accepts no events when the allowlist is empty', function () {
        config([
            'observability-log.client.channel' => 'test-channel',
            'observability-log.client.events' => [],
        ]);

        Log::shouldReceive('channel')->never();

        ClientSensor::record(browserRequest(), ['kind' => 'event', 'name' => 'copy']);
    });

    it('does not collect pageviews by default', function () {
        config(['observability-log.client.channel' => 'test-channel']);

        Log::shouldReceive('channel')->never();

        ClientSensor::record(browserRequest(), ['kind' => 'pageview', 'to' => 'https://example.test/']);
    });

    it('logs a pageview once the kind is switched on', function () {
        $entry = recordClient(['kind' => 'pageview', 'from' => '/a', 'to' => '/b', 'nav_type' => 'spa'], function () {
            config(['observability-log.client.collect' => ['pageview']]);
        });

        expect($entry['message'])->toBe('client.pageview')
            ->and($entry['context']['from'])->toBe('/a')
            ->and($entry['context']['to'])->toBe('/b')
            ->and($entry['context']['nav_type'])->toBe('spa');
    });

    it('drops a kind the app does not collect', function () {
        config([
            'observability-log.client.channel' => 'test-channel',
            'observability-log.client.collect' => ['error'],
        ]);

        Log::shouldReceive('channel')->never();

        ClientSensor::record(browserRequest(), ['kind' => 'vital', 'name' => 'LCP', 'value' => 1]);
    });

    it('drops a payload that is not an array', function () {
        config(['observability-log.client.channel' => 'test-channel']);

        Log::shouldReceive('channel')->never();

        ClientSensor::record(browserRequest(), 'kind=error');
    });

    it('drops an error with no message', function () {
        config(['observability-log.client.channel' => 'test-channel']);

        Log::shouldReceive('channel')->never();

        ClientSensor::record(browserRequest(), ['kind' => 'error', 'stack' => 'at foo']);
    });

    it('caps the stack at the configured byte length', function () {
        $entry = recordClient([
            'kind' => 'error',
            'message' => 'boom',
            'stack' => str_repeat('x', 5000),
        ], function () {
            config(['observability-log.client.stack_max_bytes' => 100]);
        });

        expect(strlen($entry['context']['stack']))->toBe(100);
    });

    it('caps the number and length of event props', function () {
        $props = ['long' => str_repeat('y', 400)];
        for ($i = 0; $i < 20; $i++) {
            $props['k'.$i] = $i;
        }

        $entry = recordClient(['kind' => 'event', 'name' => 'copy', 'props' => $props], function () {
            config([
                'observability-log.client.events' => ['copy'],
                'observability-log.client.max_props' => 3,
                'observability-log.client.prop_max_length' => 10,
            ]);
        });

        expect($entry['context']['props'])->toHaveCount(3)
            ->and(strlen($entry['context']['props']['long']))->toBe(10);
    });

    it('drops props that are not scalar', function () {
        $entry = recordClient([
            'kind' => 'event',
            'name' => 'copy',
            'props' => ['nested' => ['a' => 1], 'ok' => 'yes'],
        ], function () {
            config(['observability-log.client.events' => ['copy']]);
        });

        expect($entry['context']['props'])->toBe(['ok' => 'yes']);
    });

    it('masks the ip with the configured obfuscator', function () {
        $entry = recordClient(['kind' => 'error', 'message' => 'boom'], function () {
            config(['observability-log.client.obfuscate_ip' => [ObfuscateIp::class, 'levelOne']]);
        });

        expect($entry['context']['ip'])->toBe('203.0.113.0');
    });

    it('uses the configured message for a kind', function () {
        $entry = recordClient(['kind' => 'error', 'message' => 'boom'], function () {
            config(['observability-log.client.messages.error' => 'browser.error']);
        });

        expect($entry['message'])->toBe('browser.error');
    });

    it('lets message() override every kind', function () {
        ClientSensor::message(fn (string $kind) => 'client.'.strtoupper($kind));

        $entry = recordClient(['kind' => 'error', 'message' => 'boom']);

        expect($entry['message'])->toBe('client.ERROR');
    });

    it('lets extend() add fields', function () {
        ClientSensor::extend(function ($request, $kind, $entry) {
            $entry['tenant'] = 'acme';

            return $entry;
        });

        $entry = recordClient(['kind' => 'error', 'message' => 'boom']);

        expect($entry['context']['tenant'])->toBe('acme');
    });

    it('lets using() replace the entry', function () {
        ClientSensor::using(fn ($request, $kind, $raw) => ['kind' => $kind, 'only' => 'this']);

        $entry = recordClient(['kind' => 'error', 'message' => 'boom']);

        expect($entry['context'])->toBe(['kind' => 'error', 'only' => 'this']);
    });

    it('falls back to the default entry when using() throws', function () {
        ClientSensor::using(function () {
            throw new RuntimeException('nope');
        });

        Log::shouldReceive('error')->once();

        $entry = recordClient(['kind' => 'error', 'message' => 'boom']);

        expect($entry['context']['error_message'])->toBe('boom');
    });
});
