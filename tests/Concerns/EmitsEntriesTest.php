<?php

namespace DevtimeLtd\LaravelObservabilityLog\Tests\Concerns;

use Closure;
use DevtimeLtd\LaravelObservabilityLog\Concerns\EmitsEntries;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;

/** Exposes the trait's protected static methods for direct testing. */
class EmitsEntriesFixture
{
    use EmitsEntries;

    /**
     * @return list<string>
     */
    public static function normaliseChannelsPublic(mixed $raw): array
    {
        return self::normaliseChannels($raw);
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    public static function dispatchEntryPublic(
        mixed $channelConfig,
        string $level,
        string $message,
        array $entry
    ): void {
        self::dispatchEntry($channelConfig, $level, $message, $entry);
    }

    public static function safeCallbackPublic(Closure $callback, string $role, mixed ...$args): mixed
    {
        return self::safeCallback($callback, $role, ...$args);
    }
}

describe('normaliseChannels', function () {
    it('returns an empty list for null', function () {
        expect(EmitsEntriesFixture::normaliseChannelsPublic(null))->toBe([]);
    });

    it('returns an empty list for an empty string', function () {
        expect(EmitsEntriesFixture::normaliseChannelsPublic(''))->toBe([]);
    });

    it('returns an empty list for whitespace-only and comma-only strings', function () {
        expect(EmitsEntriesFixture::normaliseChannelsPublic('  '))->toBe([]);
        expect(EmitsEntriesFixture::normaliseChannelsPublic(','))->toBe([]);
        expect(EmitsEntriesFixture::normaliseChannelsPublic(' , , ,'))->toBe([]);
    });

    it('returns a single-element list for one channel', function () {
        expect(EmitsEntriesFixture::normaliseChannelsPublic('axiom'))->toBe(['axiom']);
    });

    it('trims whitespace around entries', function () {
        expect(EmitsEntriesFixture::normaliseChannelsPublic(' axiom ,  betterstack '))
            ->toBe(['axiom', 'betterstack']);
    });

    it('drops empty entries from anywhere in the list', function () {
        expect(EmitsEntriesFixture::normaliseChannelsPublic('a, b ,, , c'))
            ->toBe(['a', 'b', 'c']);
    });

    it('returns an empty list for non-string non-array scalar inputs', function () {
        // Channel names are only ever strings. Non-string scalars were
        // previously silently cast via (string) which produced weird
        // output like ['1'] for true; now they are dropped consistently
        // whether they appear as the whole value or as an array element.
        expect(EmitsEntriesFixture::normaliseChannelsPublic(true))->toBe([]);
        expect(EmitsEntriesFixture::normaliseChannelsPublic(false))->toBe([]);
        expect(EmitsEntriesFixture::normaliseChannelsPublic(42))->toBe([]);
        expect(EmitsEntriesFixture::normaliseChannelsPublic(1.5))->toBe([]);
    });

    it('returns an empty list for object inputs', function () {
        expect(EmitsEntriesFixture::normaliseChannelsPublic(new \stdClass))->toBe([]);
    });

    it('accepts an array of channel names directly', function () {
        expect(EmitsEntriesFixture::normaliseChannelsPublic(['axiom', 'betterstack']))
            ->toBe(['axiom', 'betterstack']);
    });

    it('trims entries and drops empties in an array input', function () {
        expect(EmitsEntriesFixture::normaliseChannelsPublic([' axiom ', '', '  ', 'betterstack']))
            ->toBe(['axiom', 'betterstack']);
    });

    it('drops non-string entries from an array input', function () {
        expect(EmitsEntriesFixture::normaliseChannelsPublic(['axiom', 42, null, ['nested'], 'betterstack']))
            ->toBe(['axiom', 'betterstack']);
    });

    it('returns an empty list for an empty array', function () {
        expect(EmitsEntriesFixture::normaliseChannelsPublic([]))->toBe([]);
    });

    it('keeps the string "0" as a valid channel name in both input shapes', function () {
        expect(EmitsEntriesFixture::normaliseChannelsPublic('0,axiom'))
            ->toBe(['0', 'axiom']);

        expect(EmitsEntriesFixture::normaliseChannelsPublic(['0', 'axiom']))
            ->toBe(['0', 'axiom']);
    });
});

describe('dispatchEntry', function () {
    it('is a no-op when the channel list resolves to empty', function () {
        Log::shouldReceive('channel')->never();
        Log::shouldReceive('stack')->never();

        EmitsEntriesFixture::dispatchEntryPublic('  , ,', 'info', 'test.message', ['a' => 1]);
    });

    it('uses Log::channel when only one channel resolves', function () {
        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->with('info', 'test.message', ['a' => 1]);

        Log::shouldReceive('channel')->with('axiom')->andReturn($channel);
        Log::shouldReceive('stack')->never();

        EmitsEntriesFixture::dispatchEntryPublic('axiom, , ', 'info', 'test.message', ['a' => 1]);
    });

    it('uses Log::stack when multiple channels resolve', function () {
        $stack = Mockery::mock();
        $stack->shouldReceive('log')
            ->once()
            ->with('info', 'test.message', ['a' => 1]);

        Log::shouldReceive('stack')->with(['axiom', 'betterstack'])->andReturn($stack);
        Log::shouldReceive('channel')->never();

        EmitsEntriesFixture::dispatchEntryPublic('axiom, betterstack', 'info', 'test.message', ['a' => 1]);
    });

    it('accepts an array channel config', function () {
        $stack = Mockery::mock();
        $stack->shouldReceive('log')->once()->with('info', 'test.message', ['a' => 1]);

        Log::shouldReceive('stack')->with(['axiom', 'betterstack'])->andReturn($stack);

        EmitsEntriesFixture::dispatchEntryPublic(['axiom', 'betterstack'], 'info', 'test.message', ['a' => 1]);
    });

    it('does not catch exceptions raised by the logger itself', function () {
        Log::shouldReceive('channel')
            ->with('broken')
            ->andThrow(new RuntimeException('log broken'));

        expect(fn () => EmitsEntriesFixture::dispatchEntryPublic('broken', 'info', 'msg', []))
            ->toThrow(RuntimeException::class, 'log broken');
    });
});

describe('safeCallback', function () {
    it('invokes the callback and returns its result', function () {
        $callback = fn (int $x, int $y) => $x + $y;

        $result = EmitsEntriesFixture::safeCallbackPublic($callback, 'using', 2, 3);

        expect($result)->toBe(5);
    });

    it('returns null when the callback throws and logs via Log::error', function () {
        Log::shouldReceive('error')
            ->once()
            ->with(Mockery::pattern('/\[EmitsEntriesFixture\] using callback threw: boom/'));

        $callback = function () {
            throw new RuntimeException('boom');
        };

        $result = EmitsEntriesFixture::safeCallbackPublic($callback, 'using');

        expect($result)->toBeNull();
    });

    it('uses the using-class name in the error tag', function () {
        Log::shouldReceive('error')
            ->once()
            ->with(Mockery::pattern('/^\[EmitsEntriesFixture\] /'));

        EmitsEntriesFixture::safeCallbackPublic(
            fn () => throw new RuntimeException('x'),
            'extend'
        );
    });

    it('tags the error with the callback role', function () {
        Log::shouldReceive('error')
            ->once()
            ->with(Mockery::pattern('/message callback threw/'));

        EmitsEntriesFixture::safeCallbackPublic(
            fn () => throw new RuntimeException('x'),
            'message'
        );
    });

    it('swallows errors raised by Log::error itself', function () {
        Log::shouldReceive('error')
            ->andThrow(new RuntimeException('log broken'));

        // Must not propagate: both the callback throw and the Log::error
        // throw have to be swallowed so the caller never sees either.
        $result = EmitsEntriesFixture::safeCallbackPublic(
            fn () => throw new RuntimeException('callback boom'),
            'using'
        );

        expect($result)->toBeNull();
    });

    it('passes a variadic spread of args to the callback', function () {
        $received = null;
        $callback = function (...$args) use (&$received) {
            $received = $args;

            return 'ok';
        };

        EmitsEntriesFixture::safeCallbackPublic($callback, 'using', 'first', 2, ['third']);

        expect($received)->toBe(['first', 2, ['third']]);
    });
});
