<?php

use DevtimeLtd\LaravelObservabilityLog\CommandSensor;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

function commandStarting(string $command, ?ArrayInput $input = null): CommandStarting
{
    return new CommandStarting($command, $input ?? new ArrayInput([]), new NullOutput);
}

function commandFinished(string $command, int $exitCode = 0, ?ArrayInput $input = null): CommandFinished
{
    return new CommandFinished($command, $input ?? new ArrayInput([]), new NullOutput, $exitCode);
}

beforeEach(function () {
    CommandSensor::using(null);
    CommandSensor::extend(null);
    CommandSensor::message(null);
    Context::flush();
    app()->forgetInstance(CommandSensor::class);
});

describe('channel resolution', function () {
    it('does not log when channel is null', function () {
        config(['observability-log.commands.channel' => null]);
        Log::shouldReceive('channel')->never();
        Log::shouldReceive('stack')->never();

        $input = new ArrayInput([]);
        CommandSensor::recordStarting(commandStarting('migrate', $input));
        CommandSensor::recordFinished(commandFinished('migrate', 0, $input));
    });

    it('uses Log::stack when multiple channels are configured', function () {
        config(['observability-log.commands.channel' => 'a,b']);

        $stack = Mockery::mock();
        $stack->shouldReceive('log')->once()->with('info', 'console.command', Mockery::type('array'));
        Log::shouldReceive('stack')->with(['a', 'b'])->andReturn($stack);

        $input = new ArrayInput([]);
        CommandSensor::recordStarting(commandStarting('migrate', $input));
        CommandSensor::recordFinished(commandFinished('migrate', 0, $input));
    });
});

describe('finished event', function () {
    it('emits command, exit_code, status=success on a zero exit', function () {
        config(['observability-log.commands.channel' => 'test']);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(function (string $level, string $message, array $context) {
                return $level === 'info'
                    && $message === 'console.command'
                    && $context['command'] === 'migrate'
                    && $context['exit_code'] === 0
                    && $context['status'] === 'success'
                    && is_float($context['duration_ms'])
                    && is_float($context['memory_peak_mb']);
            });

        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        $input = new ArrayInput([]);
        CommandSensor::recordStarting(commandStarting('migrate', $input));
        CommandSensor::recordFinished(commandFinished('migrate', 0, $input));
    });

    it('emits status=failed on a non-zero exit', function () {
        config(['observability-log.commands.channel' => 'test']);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn ($level, $message, $context) => $context['status'] === 'failed' && $context['exit_code'] === 2);

        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        $input = new ArrayInput([]);
        CommandSensor::recordStarting(commandStarting('migrate', $input));
        CommandSensor::recordFinished(commandFinished('migrate', 2, $input));
    });

    it('skips emission when no CommandStarting fired first', function () {
        config(['observability-log.commands.channel' => 'test']);
        Log::shouldReceive('channel')->never();

        CommandSensor::recordFinished(commandFinished('migrate', 0));
    });

    it('matches Starting and Finished by input instance, not command name', function () {
        config(['observability-log.commands.channel' => 'test']);

        $captured = [];
        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->twice()
            ->andReturnUsing(function ($level, $message, $context) use (&$captured) {
                $captured[] = $context;
            });

        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        $inputA = new ArrayInput([]);
        $inputB = new ArrayInput([]);

        CommandSensor::recordStarting(commandStarting('inspire', $inputA));
        CommandSensor::recordStarting(commandStarting('inspire', $inputB));
        CommandSensor::recordFinished(commandFinished('inspire', 0, $inputB));
        CommandSensor::recordFinished(commandFinished('inspire', 1, $inputA));

        expect($captured[0]['exit_code'])->toBe(0);
        expect($captured[1]['exit_code'])->toBe(1);
    });
});

describe('ignore list', function () {
    it('skips commands in the configured ignore list', function () {
        config([
            'observability-log.commands.channel' => 'test',
            'observability-log.commands.ignore' => ['schedule:run'],
        ]);

        Log::shouldReceive('channel')->never();

        $input = new ArrayInput([]);
        CommandSensor::recordStarting(commandStarting('schedule:run', $input));
        CommandSensor::recordFinished(commandFinished('schedule:run', 0, $input));
    });

    it('still logs commands not in the ignore list', function () {
        config([
            'observability-log.commands.channel' => 'test',
            'observability-log.commands.ignore' => ['schedule:run'],
        ]);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')->once();
        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        $input = new ArrayInput([]);
        CommandSensor::recordStarting(commandStarting('migrate', $input));
        CommandSensor::recordFinished(commandFinished('migrate', 0, $input));
    });

    it('matches ignore entries exactly (no prefix matching)', function () {
        config([
            'observability-log.commands.channel' => 'test',
            'observability-log.commands.ignore' => ['queue'],
        ]);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')->once();
        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        $input = new ArrayInput([]);
        CommandSensor::recordStarting(commandStarting('queue:work', $input));
        CommandSensor::recordFinished(commandFinished('queue:work', 0, $input));
    });
});

describe('trace_id', function () {
    it('is emitted from Context when set', function () {
        config(['observability-log.commands.channel' => 'test']);
        Context::add('trace_id', 'cmd-tid');

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn ($level, $message, $context) => ($context['trace_id'] ?? null) === 'cmd-tid');

        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        $input = new ArrayInput([]);
        CommandSensor::recordStarting(commandStarting('migrate', $input));
        CommandSensor::recordFinished(commandFinished('migrate', 0, $input));
    });

    it('is omitted when Context is empty', function () {
        config(['observability-log.commands.channel' => 'test']);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn ($level, $message, $context) => ! array_key_exists('trace_id', $context));

        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        $input = new ArrayInput([]);
        CommandSensor::recordStarting(commandStarting('migrate', $input));
        CommandSensor::recordFinished(commandFinished('migrate', 0, $input));
    });
});

describe('using callback', function () {
    it('replaces the default entry', function () {
        config(['observability-log.commands.channel' => 'test']);

        CommandSensor::using(function (CommandFinished $event, array $measurements) {
            return [
                'only_this' => true,
                'cmd' => $event->command,
                'duration' => $measurements['duration_ms'],
            ];
        });

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(function ($level, $message, $context) {
                return $context['only_this'] === true
                    && $context['cmd'] === 'migrate'
                    && is_float($context['duration'])
                    && ! array_key_exists('exit_code', $context);
            });

        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        $input = new ArrayInput([]);
        CommandSensor::recordStarting(commandStarting('migrate', $input));
        CommandSensor::recordFinished(commandFinished('migrate', 0, $input));
    });

    it('falls back to default when callback throws', function () {
        config(['observability-log.commands.channel' => 'test']);

        CommandSensor::using(function () {
            throw new RuntimeException('using broke');
        });

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn ($level, $message, $context) => ($context['command'] ?? null) === 'migrate');

        Log::shouldReceive('channel')->with('test')->andReturn($channel);
        Log::shouldReceive('error')->once()->with(Mockery::pattern('/using callback threw.*using broke/'));

        $input = new ArrayInput([]);
        CommandSensor::recordStarting(commandStarting('migrate', $input));
        CommandSensor::recordFinished(commandFinished('migrate', 0, $input));
    });
});

describe('extend callback', function () {
    it('adds fields after using runs', function () {
        config(['observability-log.commands.channel' => 'test']);

        CommandSensor::using(fn ($event, $measurements) => ['base' => true]);
        CommandSensor::extend(function ($event, array $entry) {
            $entry['added'] = 'yes';

            return $entry;
        });

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn ($level, $message, $context) => $context['base'] === true && $context['added'] === 'yes');

        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        $input = new ArrayInput([]);
        CommandSensor::recordStarting(commandStarting('migrate', $input));
        CommandSensor::recordFinished(commandFinished('migrate', 0, $input));
    });
});

describe('message callback', function () {
    it('uses the configured message by default', function () {
        config(['observability-log.commands.channel' => 'test']);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn ($level, $message, $context) => $message === 'console.command');

        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        $input = new ArrayInput([]);
        CommandSensor::recordStarting(commandStarting('migrate', $input));
        CommandSensor::recordFinished(commandFinished('migrate', 0, $input));
    });

    it('uses a callback when set', function () {
        config(['observability-log.commands.channel' => 'test']);

        CommandSensor::message(fn (CommandFinished $event) => 'cmd.'.$event->command);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn ($level, $message, $context) => $message === 'cmd.migrate');

        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        $input = new ArrayInput([]);
        CommandSensor::recordStarting(commandStarting('migrate', $input));
        CommandSensor::recordFinished(commandFinished('migrate', 0, $input));
    });
});

describe('failed_level config', function () {
    it('uses failed_level (default error) for non-zero exit codes', function () {
        config(['observability-log.commands.channel' => 'test']);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn ($level, $message, $context) => $level === 'error' && $context['status'] === 'failed');

        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        $input = new ArrayInput([]);
        CommandSensor::recordStarting(commandStarting('migrate', $input));
        CommandSensor::recordFinished(commandFinished('migrate', 1, $input));
    });

    it('keeps successful exits on the regular level', function () {
        config(['observability-log.commands.channel' => 'test']);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn ($level, $message, $context) => $level === 'info' && $context['status'] === 'success');

        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        $input = new ArrayInput([]);
        CommandSensor::recordStarting(commandStarting('migrate', $input));
        CommandSensor::recordFinished(commandFinished('migrate', 0, $input));
    });
});

describe('level config', function () {
    it('uses level from config', function () {
        config([
            'observability-log.commands.channel' => 'test',
            'observability-log.commands.level' => 'debug',
        ]);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn ($level, $message, $context) => $level === 'debug');

        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        $input = new ArrayInput([]);
        CommandSensor::recordStarting(commandStarting('migrate', $input));
        CommandSensor::recordFinished(commandFinished('migrate', 0, $input));
    });
});

describe('error handling', function () {
    it('swallows logger errors and resets state', function () {
        config(['observability-log.commands.channel' => 'broken']);

        Log::shouldReceive('channel')->with('broken')->andThrow(new RuntimeException('log broken'));
        Log::shouldReceive('error')->atLeast()->once();

        $input = new ArrayInput([]);
        CommandSensor::recordStarting(commandStarting('migrate', $input));
        CommandSensor::recordFinished(commandFinished('migrate', 0, $input));

        $instance = app(CommandSensor::class);
        expect((function () {
            return $this->commands;
        })->call($instance))->toBe([]);
    });
});
