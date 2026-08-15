<?php

use DevtimeLtd\LaravelObservabilityLog\ClientScript;
use DevtimeLtd\LaravelObservabilityLog\ObservabilityLogServiceProvider;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/** Re-boots the provider so route registration sees the config just set. */
function bootClientRoutes(): void
{
    app()->register(new ObservabilityLogServiceProvider(app()), true);
}

function postEntries(mixed $body): Illuminate\Testing\TestResponse
{
    return test()->call(
        'POST',
        '/_observability',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        is_string($body) ? $body : json_encode($body)
    );
}

describe('client ingest endpoint', function () {
    it('registers no route while the sensor is off', function () {
        expect(Route::has('observability-log.client'))->toBeFalse();

        postEntries(['entries' => [['kind' => 'error', 'message' => 'boom']]])
            ->assertNotFound();
    });

    it('accepts a batch and logs each entry', function () {
        config([
            'observability-log.client.channel' => 'test-channel',
            'observability-log.client.events' => ['copy'],
        ]);
        bootClientRoutes();

        $channel = Mockery::mock();
        $channel->shouldReceive('log')->twice();
        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        postEntries(['entries' => [
            ['kind' => 'error', 'message' => 'boom'],
            ['kind' => 'event', 'name' => 'copy'],
        ]])->assertNoContent();
    });

    it('accepts a bare list as well as an entries key', function () {
        config(['observability-log.client.channel' => 'test-channel']);
        bootClientRoutes();

        $channel = Mockery::mock();
        $channel->shouldReceive('log')->once();
        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        postEntries([['kind' => 'error', 'message' => 'boom']])->assertNoContent();
    });

    it('drops a body over the byte cap before decoding it', function () {
        config([
            'observability-log.client.channel' => 'test-channel',
            'observability-log.client.max_body_bytes' => 64,
        ]);
        bootClientRoutes();

        Log::shouldReceive('channel')->never();

        postEntries(['entries' => [
            ['kind' => 'error', 'message' => str_repeat('x', 500)],
        ]])->assertNoContent();
    });

    it('truncates a batch to the entry cap', function () {
        config([
            'observability-log.client.channel' => 'test-channel',
            'observability-log.client.max_entries' => 2,
            'observability-log.client.max_body_bytes' => 65536,
        ]);
        bootClientRoutes();

        $channel = Mockery::mock();
        $channel->shouldReceive('log')->twice();
        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        $entries = array_fill(0, 10, ['kind' => 'error', 'message' => 'boom']);

        postEntries(['entries' => $entries])->assertNoContent();
    });

    it('answers 204 to a body that is not json', function () {
        config(['observability-log.client.channel' => 'test-channel']);
        bootClientRoutes();

        Log::shouldReceive('channel')->never();

        postEntries('not json at all')->assertNoContent();
    });

    it('answers 204 to a json scalar', function () {
        config(['observability-log.client.channel' => 'test-channel']);
        bootClientRoutes();

        Log::shouldReceive('channel')->never();

        postEntries('"just a string"')->assertNoContent();
    });

    it('rate limits with the configured middleware', function () {
        config([
            'observability-log.client.channel' => 'test-channel',
            'observability-log.client.middleware' => ['throttle:2,1'],
        ]);
        bootClientRoutes();

        Log::shouldReceive('channel')->andReturn(Mockery::mock()->shouldReceive('log')->andReturnNull()->getMock());

        postEntries([])->assertNoContent();
        postEntries([])->assertNoContent();
        postEntries([])->assertStatus(429);
    });
});

describe('client script directive', function () {
    beforeEach(fn () => ClientScript::flushSource());

    it('renders nothing while the sensor is off', function () {
        config(['observability-log.client.channel' => null]);

        expect(ClientScript::render())->toBe('');
    });

    it('renders the agent and its config once switched on', function () {
        config([
            'observability-log.client.channel' => 'test-channel',
            'observability-log.client.path' => '_obs',
            'observability-log.client.sample_rate' => 0.25,
        ]);

        $html = ClientScript::render(['trace_id' => 'trace-1']);

        expect($html)->toStartWith('<script>')
            ->and($html)->toEndWith('</script>')
            ->and($html)->toContain('"endpoint":"/_obs"')
            ->and($html)->toContain('"sample":0.25')
            ->and($html)->toContain('"trace_id":"trace-1"')
            ->and($html)->toContain('window.__observability=');
    });

    it('carries a csp nonce when given one', function () {
        config(['observability-log.client.channel' => 'test-channel']);

        expect(ClientScript::render(['nonce' => 'abc123']))->toStartWith('<script nonce="abc123">');
    });

    it('escapes a trace id that would close the script tag', function () {
        config(['observability-log.client.channel' => 'test-channel']);

        $html = ClientScript::render(['trace_id' => '</script><script>alert(1)</script>']);

        expect($html)->not->toContain('</script><script>alert(1)')
            ->and(substr_count($html, '</script>'))->toBe(1);
    });

    it('renders through the blade directive', function () {
        config(['observability-log.client.channel' => 'test-channel']);

        expect(Illuminate\Support\Facades\Blade::render('@observability'))
            ->toContain('window.__observability=');
    });

    it('passes options through the blade directive', function () {
        config(['observability-log.client.channel' => 'test-channel']);

        expect(Illuminate\Support\Facades\Blade::render("@observability(['nonce' => 'n1'])"))
            ->toStartWith('<script nonce="n1">');
    });
});
