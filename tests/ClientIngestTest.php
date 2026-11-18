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

const UUID_V4 = '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

/** @return array<string, mixed> */
function agentConfig(string $html): array
{
    preg_match('/window\.__observability=(\{.*?\});/s', $html, $found);

    return json_decode($found[1], true, flags: JSON_THROW_ON_ERROR);
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

    it('refuses an oversized body on its declared length', function () {
        config([
            'observability-log.client.channel' => 'test-channel',
            'observability-log.client.max_body_bytes' => 64,
        ]);
        bootClientRoutes();

        Log::shouldReceive('channel')->never();

        test()->call('POST', '/_observability', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'CONTENT_LENGTH' => '99999',
        ], json_encode(['entries' => [['kind' => 'error', 'message' => 'boom']]]))
            ->assertNoContent();
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
    beforeEach(function () {
        ClientScript::flushSource();
        Illuminate\Support\Facades\Context::flush();
    });

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

    it('prefixes the endpoint for an app mounted in a subdirectory', function () {
        config([
            'observability-log.client.channel' => 'test-channel',
            'observability-log.client.path' => '_obs',
        ]);

        // A request whose script name puts Laravel under /app.
        $request = Illuminate\Http\Request::create('/app/pricing', 'GET', [], [], [], [
            'SCRIPT_FILENAME' => '/var/www/app/public/index.php',
            'SCRIPT_NAME' => '/app/index.php',
            'PHP_SELF' => '/app/index.php',
        ]);
        app()->instance('request', $request);

        expect(ClientScript::render())->toContain('"endpoint":"/app/_obs"');
    });

    it('tells the browser the body cap so it can split a batch', function () {
        config([
            'observability-log.client.channel' => 'test-channel',
            'observability-log.client.max_body_bytes' => 4096,
        ]);

        expect(ClientScript::render())->toContain('"max_bytes":4096');
    });

    it('escapes a trace id that would close the script tag', function () {
        config(['observability-log.client.channel' => 'test-channel']);

        $html = ClientScript::render(['trace_id' => '</script><script>alert(1)</script>']);

        expect($html)->not->toContain('</script><script>alert(1)')
            ->and(substr_count($html, '</script>'))->toBe(1);
    });

    it('hands the page an instance and a first visit, shares them to Context and names the headers', function () {
        config(['observability-log.client.channel' => 'test-channel']);

        $config = agentConfig(ClientScript::render());
        $instance = Illuminate\Support\Facades\Context::get('client_instance_id');
        $visit = Illuminate\Support\Facades\Context::get('visit_id');

        expect($instance)->toMatch(UUID_V4)
            ->and($visit)->toMatch(UUID_V4)
            ->and($visit)->not->toBe($instance)
            ->and($config['client_instance_id'])->toBe($instance)
            ->and($config['visit_id'])->toBe($visit)
            ->and($config['headers'])->toBe([
                'client_id' => 'X-Client-Id',
                'client_instance_id' => 'X-Client-Instance-Id',
                'visit_id' => 'X-Visit-Id',
            ])
            ->and($config)->not->toHaveKey('client_id')
            ->and(Illuminate\Support\Facades\Context::has('client_id'))->toBeFalse();
    });

    it('keeps the ids a request arrived with', function () {
        config(['observability-log.client.channel' => 'test-channel']);

        app()->instance('request', Illuminate\Http\Request::create('/pricing', 'GET', [], [], [], [
            'HTTP_X_CLIENT_INSTANCE_ID' => 'the-instance',
            'HTTP_X_VISIT_ID' => 'the-visit',
        ]));

        expect(agentConfig(ClientScript::render()))
            ->toMatchArray(['client_instance_id' => 'the-instance', 'visit_id' => 'the-visit']);
    });

    it('uses the ids the app supplies', function () {
        config(['observability-log.client.channel' => 'test-channel']);

        $ids = [
            'client_id' => 'the-apps-device',
            'client_instance_id' => 'the-apps-instance',
            'visit_id' => 'the-apps-visit',
        ];

        expect(agentConfig(ClientScript::render($ids)))->toMatchArray($ids)
            ->and(Illuminate\Support\Facades\Context::only(array_keys($ids)))->toBe($ids);
    });

    it('mints nothing into a page that may be replayed from a cache', function () {
        config(['observability-log.client.channel' => 'test-channel']);

        Illuminate\Support\Facades\Context::add('trace_id', 'this-requests-trace');

        expect(agentConfig(ClientScript::render(['cacheable' => true])))
            ->not->toHaveKeys(['client_instance_id', 'visit_id'])
            ->trace_id->toBeNull()
            ->and(agentConfig(ClientScript::render(['cacheable' => true]))['headers']['visit_id'])->toBe('X-Visit-Id')
            ->and(Illuminate\Support\Facades\Context::all())->toBe(['trace_id' => 'this-requests-trace']);
    });

    it('leaves a client id the app supplied out of a page that may be cached too', function () {
        config(['observability-log.client.channel' => 'test-channel']);

        expect(agentConfig(ClientScript::render(['cacheable' => true, 'client_id' => 'the-first-visitors'])))
            ->not->toHaveKey('client_id')
            ->and(agentConfig(ClientScript::render(['cacheable' => true, 'client_id' => false]))['client_id'])->toBeFalse()
            ->and(Illuminate\Support\Facades\Context::all())->toBe([]);
    });

    it('tells the browser to keep no client id when it is switched off', function (array $config, array $options) {
        config(['observability-log.client.channel' => 'test-channel', ...$config]);

        expect(agentConfig(ClientScript::render($options))['client_id'])->toBeFalse();
    })->with([
        'in config' => [['observability-log.client_id' => null], []],
        'for one page' => [[], ['client_id' => false]],
    ]);

    it('mints and sends nothing for an id that is switched off', function () {
        config([
            'observability-log.client.channel' => 'test-channel',
            'observability-log.visit_id' => null,
        ]);

        $config = agentConfig(ClientScript::render(['visit_id' => 'the-apps-visit']));

        expect($config['headers'])->toBe([
            'client_id' => 'X-Client-Id',
            'client_instance_id' => 'X-Client-Instance-Id',
            'visit_id' => null,
        ])
            ->and($config)->toHaveKey('client_instance_id')
            ->and($config)->not->toHaveKey('visit_id')
            ->and(Illuminate\Support\Facades\Context::has('visit_id'))->toBeFalse();
    });

    it('follows inertia only when asked to', function (array $options, mixed $expected) {
        config(['observability-log.client.channel' => 'test-channel']);

        expect(agentConfig(ClientScript::render($options))['inertia'])->toBe($expected);
    })->with([
        'by default' => [[], false],
        'asked' => [['inertia' => true], true],
        'with the trace id prop' => [['inertia' => ['trace_id' => 'traceId']], ['trace_id' => 'traceId']],
        'with no prop named' => [['inertia' => []], true],
    ]);

    it('tells the browser the build and the view', function () {
        config([
            'observability-log.client.channel' => 'test-channel',
            'observability-log.client.release' => 'a1b2c3d',
        ]);

        expect(ClientScript::render(['view' => 'pricing']))
            ->toContain('"release":"a1b2c3d"')
            ->toContain('"view":"pricing"');

        expect(ClientScript::render(['release' => 'e4f5a6b']))->toContain('"release":"e4f5a6b"');
    });

    it('renders through the blade directive', function () {
        config(['observability-log.client.channel' => 'test-channel']);

        expect(Illuminate\Support\Facades\Blade::render('@observabilityClient'))
            ->toContain('window.__observability=');
    });

    it('passes options through the blade directive', function () {
        config(['observability-log.client.channel' => 'test-channel']);

        expect(Illuminate\Support\Facades\Blade::render("@observabilityClient(['nonce' => 'n1'])"))
            ->toStartWith('<script nonce="n1">');
    });
});
