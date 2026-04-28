<?php

use DevtimeLtd\LaravelObservabilityLog\ExceptionSensor;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    ExceptionSensor::using(null);
    ExceptionSensor::extend(null);
    ExceptionSensor::message(null);
    Context::flush();
});

describe('exception logging', function () {
    it('logs exception details to the configured channel', function () {
        config(['observability-log.exceptions.channel' => 'test-channel']);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(function (string $level, string $message, array $context) {
                return $level === 'error'
                    && $message === 'error.exception'
                    && $context['class'] === RuntimeException::class
                    && $context['message'] === 'boom'
                    && is_int($context['line'])
                    && is_string($context['file'])
                    && is_string($context['trace']);
            });

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        ExceptionSensor::report(new RuntimeException('boom'));
    });

    it('does not log when the channel is null', function () {
        config(['observability-log.exceptions.channel' => null]);

        Log::shouldReceive('channel')->never();

        ExceptionSensor::report(new RuntimeException('boom'));
    });

    it('does not log when the channel is an empty string', function () {
        config(['observability-log.exceptions.channel' => '']);

        Log::shouldReceive('channel')->never();

        ExceptionSensor::report(new RuntimeException('boom'));
    });

    it('uses Log::stack when multiple channels are configured', function () {
        config(['observability-log.exceptions.channel' => 'a,b']);

        $stack = Mockery::mock();
        $stack->shouldReceive('log')->once()->with('error', 'error.exception', Mockery::type('array'));

        Log::shouldReceive('stack')->with(['a', 'b'])->andReturn($stack);

        ExceptionSensor::report(new RuntimeException('boom'));
    });

    it('trims whitespace and drops empty entries in the channel list', function () {
        config(['observability-log.exceptions.channel' => 'a, b ,']);

        $stack = Mockery::mock();
        $stack->shouldReceive('log')->once();

        Log::shouldReceive('stack')->with(['a', 'b'])->andReturn($stack);

        ExceptionSensor::report(new RuntimeException('boom'));
    });

    it('uses Log::channel when the comma-separated list normalises to one entry', function () {
        config(['observability-log.exceptions.channel' => 'a,']);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')->once();

        Log::shouldReceive('channel')->with('a')->andReturn($channel);

        ExceptionSensor::report(new RuntimeException('boom'));
    });

    it('does not log when the channel list normalises to nothing', function () {
        config(['observability-log.exceptions.channel' => ' , ,']);

        Log::shouldReceive('channel')->never();
        Log::shouldReceive('stack')->never();

        ExceptionSensor::report(new RuntimeException('boom'));
    });

    it('swallows logger errors rather than propagating', function () {
        config(['observability-log.exceptions.channel' => 'broken']);

        Log::shouldReceive('channel')->with('broken')->andThrow(new RuntimeException('log broken'));
        Log::shouldReceive('error')->once()->with(Mockery::pattern('/log broken/'));

        ExceptionSensor::report(new RuntimeException('boom'));

        expect(true)->toBeTrue();
    });

    it('short-circuits re-entrant reporting', function () {
        config(['observability-log.exceptions.channel' => 'test-channel']);

        $calls = 0;
        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->twice()
            ->andReturnUsing(function () use (&$calls) {
                $calls++;
                // Inside the first call, try to report again. The static
                // guard should ensure the nested call no-ops.
                if ($calls === 1) {
                    ExceptionSensor::report(new RuntimeException('inner'));
                }
            });

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        ExceptionSensor::report(new RuntimeException('outer'));

        // After the outer call, report() is reachable again.
        ExceptionSensor::report(new RuntimeException('after'));

        expect($calls)->toBe(2);
    });
});

describe('ignore list', function () {
    it('skips exceptions matching an ignored class', function () {
        config([
            'observability-log.exceptions.channel' => 'test-channel',
            'observability-log.exceptions.ignore' => [RuntimeException::class],
        ]);

        Log::shouldReceive('channel')->never();

        ExceptionSensor::report(new RuntimeException('boom'));
    });

    it('matches subclasses via is_a()', function () {
        config([
            'observability-log.exceptions.channel' => 'test-channel',
            'observability-log.exceptions.ignore' => [Exception::class],
        ]);

        Log::shouldReceive('channel')->never();

        ExceptionSensor::report(new RuntimeException('boom'));
    });

    it('still reports exceptions not in the ignore list', function () {
        config([
            'observability-log.exceptions.channel' => 'test-channel',
            'observability-log.exceptions.ignore' => [LogicException::class],
        ]);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')->once();

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        ExceptionSensor::report(new RuntimeException('boom'));
    });
});

describe('trace config', function () {
    it('omits the trace when trace is false', function () {
        config([
            'observability-log.exceptions.channel' => 'test-channel',
            'observability-log.exceptions.trace' => false,
        ]);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level, string $message, array $context) => ! array_key_exists('trace', $context));

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        ExceptionSensor::report(new RuntimeException('boom'));
    });

    it('emits trace as a string by default', function () {
        config(['observability-log.exceptions.channel' => 'test-channel']);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level, string $message, array $context) => is_string($context['trace'] ?? null));

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        ExceptionSensor::report(new RuntimeException('boom'));
    });

    it('emits trace as a structured array when trace_args is true', function () {
        config([
            'observability-log.exceptions.channel' => 'test-channel',
            'observability-log.exceptions.trace_args' => true,
        ]);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(function (string $level, string $message, array $context) {
                return is_array($context['trace'] ?? null);
            });

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        ExceptionSensor::report(new RuntimeException('boom'));
    });
});

describe('previous chain', function () {
    it('includes up to 3 previous levels', function () {
        config(['observability-log.exceptions.channel' => 'test-channel']);

        $deepest = new RuntimeException('one');
        $level2 = new RuntimeException('two', 0, $deepest);
        $level3 = new RuntimeException('three', 0, $level2);
        $level4 = new RuntimeException('four', 0, $level3);
        $outer = new RuntimeException('outer', 0, $level4);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(function (string $level, string $message, array $context) {
                $previous = $context['previous'] ?? [];

                return is_array($previous)
                    && count($previous) === 3
                    && $previous[0]['message'] === 'four'
                    && $previous[1]['message'] === 'three'
                    && $previous[2]['message'] === 'two';
            });

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        ExceptionSensor::report($outer);
    });

    it('omits previous when there is none', function () {
        config(['observability-log.exceptions.channel' => 'test-channel']);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level, string $message, array $context) => ! array_key_exists('previous', $context));

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        ExceptionSensor::report(new RuntimeException('boom'));
    });

    it('honours previous_max_depth config', function () {
        config([
            'observability-log.exceptions.channel' => 'test-channel',
            'observability-log.exceptions.previous_max_depth' => 1,
        ]);

        $deepest = new RuntimeException('one');
        $level2 = new RuntimeException('two', 0, $deepest);
        $level3 = new RuntimeException('three', 0, $level2);
        $outer = new RuntimeException('outer', 0, $level3);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(function (string $level, string $message, array $context) {
                $previous = $context['previous'] ?? [];

                return count($previous) === 1 && $previous[0]['message'] === 'three';
            });

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        ExceptionSensor::report($outer);
    });

    it('captures an unbounded chain when previous_max_depth is null', function () {
        config([
            'observability-log.exceptions.channel' => 'test-channel',
            'observability-log.exceptions.previous_max_depth' => null,
        ]);

        $deepest = new RuntimeException('one');
        $chain = $deepest;
        for ($i = 2; $i <= 10; $i++) {
            $chain = new RuntimeException('level-'.$i, 0, $chain);
        }
        $outer = new RuntimeException('outer', 0, $chain);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(function (string $level, string $message, array $context) {
                return count($context['previous'] ?? []) === 10;
            });

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        ExceptionSensor::report($outer);
    });

    it('omits previous entirely when previous_max_depth is 0', function () {
        config([
            'observability-log.exceptions.channel' => 'test-channel',
            'observability-log.exceptions.previous_max_depth' => 0,
        ]);

        $outer = new RuntimeException('outer', 0, new RuntimeException('inner'));

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level, string $message, array $context) => ! array_key_exists('previous', $context));

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        ExceptionSensor::report($outer);
    });
});

describe('header capture', function () {
    it('omits headers by default', function () {
        config(['observability-log.exceptions.channel' => 'test-channel']);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level, string $message, array $context) => ! array_key_exists('headers', $context));

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        ExceptionSensor::report(new RuntimeException('boom'));
    });

    it('includes headers when capture_headers is enabled and the HTTP kernel is resolved', function () {
        config([
            'observability-log.exceptions.channel' => 'test-channel',
            'observability-log.exceptions.capture_headers' => true,
        ]);

        // Force the HTTP kernel to resolve so resolveRequest() short-circuit allows it.
        app(\Illuminate\Contracts\Http\Kernel::class);

        app()->instance('request', Illuminate\Http\Request::create('/test', 'GET', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer secret',
            'HTTP_X_TENANT_ID' => 'acme',
        ]));

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(function (string $level, string $message, array $context) {
                return isset($context['headers'])
                    && ($context['headers']['authorization'] ?? null) === '[redacted]'
                    && ($context['headers']['x-tenant-id'] ?? null) === 'acme';
            });

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        ExceptionSensor::report(new RuntimeException('boom'));
    });
});

describe('trace_string_max_bytes', function () {
    it('truncates a string trace exceeding the cap', function () {
        config([
            'observability-log.exceptions.channel' => 'test-channel',
            'observability-log.exceptions.trace_string_max_bytes' => 50,
        ]);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(function (string $level, string $message, array $context) {
                $trace = $context['trace'] ?? '';

                return is_string($trace)
                    && str_contains($trace, '[truncated at 50 bytes]');
            });

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        // Force a deeper trace by nesting calls.
        $thrower = function () {
            throw new RuntimeException('boom');
        };

        try {
            (fn () => (fn () => (fn () => $thrower())())())();
        } catch (RuntimeException $e) {
            ExceptionSensor::report($e);
        }
    });

    it('disables truncation when trace_string_max_bytes is null', function () {
        config([
            'observability-log.exceptions.channel' => 'test-channel',
            'observability-log.exceptions.trace_string_max_bytes' => null,
        ]);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(function (string $level, string $message, array $context) {
                $trace = $context['trace'] ?? '';

                return is_string($trace) && ! str_contains($trace, '[truncated');
            });

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        ExceptionSensor::report(new RuntimeException('boom'));
    });

    it('does not truncate structured traces from trace_args', function () {
        config([
            'observability-log.exceptions.channel' => 'test-channel',
            'observability-log.exceptions.trace_args' => true,
            'observability-log.exceptions.trace_string_max_bytes' => 50,
        ]);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level, string $message, array $context) => is_array($context['trace'] ?? null));

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        ExceptionSensor::report(new RuntimeException('boom'));
    });

    it('preserves valid UTF-8 after truncation', function () {
        config([
            'observability-log.exceptions.channel' => 'test-channel',
            'observability-log.exceptions.trace_string_max_bytes' => 50,
        ]);

        $captured = null;
        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->andReturnUsing(function (string $level, string $message, array $context) use (&$captured) {
                $captured = $context;
            });

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        $thrower = function () {
            throw new RuntimeException('boom');
        };

        try {
            (fn () => (fn () => $thrower())())();
        } catch (RuntimeException $e) {
            ExceptionSensor::report($e);
        }

        expect(mb_check_encoding($captured['trace'], 'UTF-8'))->toBeTrue();
        expect($captured['trace'])->toContain('[truncated at 50 bytes]');
    });

    it('prefers to cut at a frame boundary', function () {
        config([
            'observability-log.exceptions.channel' => 'test-channel',
            'observability-log.exceptions.trace_string_max_bytes' => 200,
        ]);

        $captured = null;
        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->andReturnUsing(function (string $level, string $message, array $context) use (&$captured) {
                $captured = $context;
            });

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        $thrower = function () {
            throw new RuntimeException('boom');
        };

        try {
            (fn () => (fn () => (fn () => $thrower())())())();
        } catch (RuntimeException $e) {
            ExceptionSensor::report($e);
        }

        // The truncation marker should appear on its own line, which
        // means the cut was made at a frame boundary (preceding newline).
        expect($captured['trace'])->toContain("\n... [truncated at 200 bytes]");
    });
});

describe('trace_args_max_frames', function () {
    it('caps the number of structured frames emitted', function () {
        config([
            'observability-log.exceptions.channel' => 'test-channel',
            'observability-log.exceptions.trace_args' => true,
            'observability-log.exceptions.trace_args_max_frames' => 2,
        ]);

        $captured = null;
        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->andReturnUsing(function (string $level, string $message, array $context) use (&$captured) {
                $captured = $context;
            });

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        $thrower = function () {
            throw new RuntimeException('boom');
        };

        try {
            (fn () => (fn () => (fn () => $thrower())())())();
        } catch (RuntimeException $e) {
            ExceptionSensor::report($e);
        }

        expect($captured['trace'])->toBeArray();
        expect(count($captured['trace']))->toBe(3); // 2 frames + truncation marker
        expect(end($captured['trace']))->toHaveKey('truncated');
    });

    it('does not truncate when cap is null', function () {
        config([
            'observability-log.exceptions.channel' => 'test-channel',
            'observability-log.exceptions.trace_args' => true,
            'observability-log.exceptions.trace_args_max_frames' => null,
        ]);

        $captured = null;
        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->andReturnUsing(function (string $level, string $message, array $context) use (&$captured) {
                $captured = $context;
            });

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        ExceptionSensor::report(new RuntimeException('boom'));

        foreach ($captured['trace'] as $frame) {
            expect($frame)->not->toHaveKey('truncated');
        }
    });
});

describe('trace_args structure', function () {
    it('includes an args key on each frame when enabled', function () {
        config([
            'observability-log.exceptions.channel' => 'test-channel',
            'observability-log.exceptions.trace_args' => true,
        ]);

        $captured = null;
        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->andReturnUsing(function (string $level, string $message, array $context) use (&$captured) {
                $captured = $context;
            });

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        $throw = function (string $secret) {
            throw new RuntimeException('boom');
        };

        try {
            $throw('hunter2');
        } catch (RuntimeException $e) {
            ExceptionSensor::report($e);
        }

        expect($captured['trace'])->toBeArray();

        $hasArgsKey = false;
        foreach ($captured['trace'] as $frame) {
            if (array_key_exists('args', $frame)) {
                $hasArgsKey = true;
                break;
            }
        }

        expect($hasArgsKey)->toBeTrue();
    });

    it('leaks sensitive argument values (documenting the privacy hazard)', function () {
        config([
            'observability-log.exceptions.channel' => 'test-channel',
            'observability-log.exceptions.trace_args' => true,
        ]);

        // Force zend.exception_ignore_args=Off so trace args are populated.
        $previous = ini_set('zend.exception_ignore_args', '0');

        if ($previous === false) {
            expect(true)->toBeTrue();

            return;
        }

        $captured = null;
        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->andReturnUsing(function (string $level, string $message, array $context) use (&$captured) {
                $captured = $context;
            });

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        $raiser = new class
        {
            public function raise(string $password): void
            {
                throw new RuntimeException('boom');
            }
        };

        try {
            $raiser->raise('hunter2-pii');
        } catch (RuntimeException $e) {
            ExceptionSensor::report($e);
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }

        $serialized = json_encode($captured['trace']);

        expect($serialized)->toContain('hunter2-pii');
    });
});

describe('callback error handling', function () {
    it('falls back to the default entry when the using callback throws', function () {
        config(['observability-log.exceptions.channel' => 'test-channel']);

        ExceptionSensor::using(function () {
            throw new RuntimeException('using broke');
        });

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(function (string $level, string $message, array $context) {
                // Default entry has class/message/file/line
                return ($context['class'] ?? null) === RuntimeException::class
                    && ($context['message'] ?? null) === 'boom';
            });

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);
        Log::shouldReceive('error')->once()->with(Mockery::pattern('/using callback threw.*using broke/'));

        ExceptionSensor::report(new RuntimeException('boom'));
    });

    it('keeps the default entry when the extend callback throws', function () {
        config(['observability-log.exceptions.channel' => 'test-channel']);

        ExceptionSensor::extend(function () {
            throw new RuntimeException('extend broke');
        });

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level, string $message, array $context) => ($context['class'] ?? null) === RuntimeException::class);

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);
        Log::shouldReceive('error')->once()->with(Mockery::pattern('/extend callback threw.*extend broke/'));

        ExceptionSensor::report(new RuntimeException('boom'));
    });

    it('uses the config message when the message callback throws', function () {
        config(['observability-log.exceptions.channel' => 'test-channel']);

        ExceptionSensor::message(function () {
            throw new RuntimeException('message broke');
        });

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level, string $message, array $context) => $message === 'error.exception');

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);
        Log::shouldReceive('error')->once()->with(Mockery::pattern('/message callback threw.*message broke/'));

        ExceptionSensor::report(new RuntimeException('boom'));
    });
});

describe('HTTP vs console context', function () {
    it('skips request fields when the HTTP kernel has not been resolved', function () {
        config(['observability-log.exceptions.channel' => 'test-channel']);

        // Ensure the HTTP kernel is NOT resolved in this test context.
        // The test runner is CLI-based and nothing resolves the kernel
        // unless $this->get(...) is called.
        $httpKernelResolved = app()->resolved(\Illuminate\Contracts\Http\Kernel::class);

        expect($httpKernelResolved)->toBeFalse();

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(function (string $level, string $message, array $context) {
                return ! array_key_exists('method', $context)
                    && ! array_key_exists('url', $context)
                    && ! array_key_exists('headers', $context);
            });

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        ExceptionSensor::report(new RuntimeException('boom'));
    });
});

describe('trace_id', function () {
    it('is emitted from Context when set', function () {
        config(['observability-log.exceptions.channel' => 'test-channel']);
        Context::add('trace_id', 'ctx-42');

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level, string $message, array $context) => ($context['trace_id'] ?? null) === 'ctx-42');

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        ExceptionSensor::report(new RuntimeException('boom'));
    });

    it('is omitted when Context is empty and no request is bound', function () {
        config(['observability-log.exceptions.channel' => 'test-channel']);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level, string $message, array $context) => ! array_key_exists('trace_id', $context));

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        ExceptionSensor::report(new RuntimeException('boom'));
    });
});

describe('using callback', function () {
    it('replaces the default entry', function () {
        config(['observability-log.exceptions.channel' => 'test-channel']);

        ExceptionSensor::using(fn (Throwable $e) => [
            'only_this' => true,
            'exception_class' => get_class($e),
        ]);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(function (string $level, string $message, array $context) {
                return ($context['only_this'] ?? null) === true
                    && ($context['exception_class'] ?? null) === RuntimeException::class
                    && ! array_key_exists('file', $context);
            });

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        ExceptionSensor::report(new RuntimeException('boom'));
    });
});

describe('extend callback', function () {
    it('adds fields to the default entry', function () {
        config(['observability-log.exceptions.channel' => 'test-channel']);

        ExceptionSensor::extend(function (Throwable $e, array $entry) {
            $entry['custom'] = 'value';

            return $entry;
        });

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(function (string $level, string $message, array $context) {
                return ($context['custom'] ?? null) === 'value'
                    && ($context['class'] ?? null) === RuntimeException::class;
            });

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        ExceptionSensor::report(new RuntimeException('boom'));
    });

    it('composes with using()', function () {
        config(['observability-log.exceptions.channel' => 'test-channel']);

        ExceptionSensor::using(fn (Throwable $e) => ['base' => true]);
        ExceptionSensor::extend(function (Throwable $e, array $entry) {
            $entry['added'] = 'yes';

            return $entry;
        });

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level, string $message, array $context) => $context['base'] === true && $context['added'] === 'yes');

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        ExceptionSensor::report(new RuntimeException('boom'));
    });
});

describe('message callback', function () {
    it('defaults to error.exception', function () {
        config(['observability-log.exceptions.channel' => 'test-channel']);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level, string $message, array $context) => $message === 'error.exception');

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        ExceptionSensor::report(new RuntimeException('boom'));
    });

    it('uses a callback when set', function () {
        config(['observability-log.exceptions.channel' => 'test-channel']);

        ExceptionSensor::message(fn (Throwable $e) => 'custom.'.strtolower(class_basename($e)));

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level, string $message, array $context) => $message === 'custom.runtimeexception');

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        ExceptionSensor::report(new RuntimeException('boom'));
    });

    it('accepts a fixed string', function () {
        config(['observability-log.exceptions.channel' => 'test-channel']);

        ExceptionSensor::message('static.exception');

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level, string $message, array $context) => $message === 'static.exception');

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        ExceptionSensor::report(new RuntimeException('boom'));
    });

    it('falls back to config after message(null)', function () {
        config(['observability-log.exceptions.channel' => 'test-channel']);

        ExceptionSensor::message('custom');
        ExceptionSensor::message(null);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level, string $message, array $context) => $message === 'error.exception');

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        ExceptionSensor::report(new RuntimeException('boom'));
    });
});

describe('level config', function () {
    it('uses level from config', function () {
        config([
            'observability-log.exceptions.channel' => 'test-channel',
            'observability-log.exceptions.level' => 'critical',
        ]);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level, string $message, array $context) => $level === 'critical');

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        ExceptionSensor::report(new RuntimeException('boom'));
    });
});

describe('exception context()', function () {
    it('captures the context() array on the root exception', function () {
        config(['observability-log.exceptions.channel' => 'test-channel']);

        $e = new class('boom') extends RuntimeException
        {
            public function context(): array
            {
                return ['order_id' => 123, 'user_id' => 7];
            }
        };

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(function (string $level, string $message, array $context) {
                return ($context['exception_context'] ?? null) === ['order_id' => 123, 'user_id' => 7];
            });

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        ExceptionSensor::report($e);
    });

    it('omits exception_context when context() returns an empty array', function () {
        config(['observability-log.exceptions.channel' => 'test-channel']);

        $e = new class('boom') extends RuntimeException
        {
            public function context(): array
            {
                return [];
            }
        };

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level, string $message, array $context) => ! array_key_exists('exception_context', $context));

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        ExceptionSensor::report($e);
    });

    it('omits exception_context when context() returns a non-array', function () {
        config(['observability-log.exceptions.channel' => 'test-channel']);

        $e = new class('boom') extends RuntimeException
        {
            public function context(): mixed
            {
                return 'not-an-array';
            }
        };

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level, string $message, array $context) => ! array_key_exists('exception_context', $context));

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        ExceptionSensor::report($e);
    });

    it('omits exception_context when the exception has no context() method', function () {
        config(['observability-log.exceptions.channel' => 'test-channel']);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level, string $message, array $context) => ! array_key_exists('exception_context', $context));

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        ExceptionSensor::report(new RuntimeException('boom'));
    });

    it('swallows exceptions thrown by context() and omits the field', function () {
        config(['observability-log.exceptions.channel' => 'test-channel']);

        $e = new class('boom') extends RuntimeException
        {
            public function context(): array
            {
                throw new RuntimeException('context blew up');
            }
        };

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level, string $message, array $context) => ! array_key_exists('exception_context', $context));

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        ExceptionSensor::report($e);
    });

    it('attaches context() to previous frames when present', function () {
        config(['observability-log.exceptions.channel' => 'test-channel']);

        $inner = new class('inner') extends RuntimeException
        {
            public function context(): array
            {
                return ['stage' => 'payment'];
            }
        };

        $outer = new RuntimeException('outer', 0, $inner);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(function (string $level, string $message, array $context) {
                $previous = $context['previous'] ?? [];

                return count($previous) === 1
                    && $previous[0]['message'] === 'inner'
                    && ($previous[0]['context'] ?? null) === ['stage' => 'payment'];
            });

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        ExceptionSensor::report($outer);
    });

    it('omits context on previous frames when context() is missing', function () {
        config(['observability-log.exceptions.channel' => 'test-channel']);

        $inner = new RuntimeException('inner');
        $outer = new RuntimeException('outer', 0, $inner);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(function (string $level, string $message, array $context) {
                $previous = $context['previous'] ?? [];

                return count($previous) === 1
                    && ! array_key_exists('context', $previous[0]);
            });

        Log::shouldReceive('channel')->with('test-channel')->andReturn($channel);

        ExceptionSensor::report($outer);
    });
});
