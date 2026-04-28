<?php

use DevtimeLtd\LaravelObservabilityLog\ScheduledTaskSensor;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

function scheduledEvent(string $command = 'php artisan inspire', string $expression = '* * * * *', ?string $description = null): ScheduledEvent
{
    $mutex = new CacheEventMutex(app('cache'));
    $event = new ScheduledEvent($mutex, $command);
    $event->expression = $expression;

    if ($description !== null) {
        $event->description($description);
    }

    return $event;
}

beforeEach(function () {
    ScheduledTaskSensor::using(null);
    ScheduledTaskSensor::extend(null);
    ScheduledTaskSensor::message(null);
    Context::flush();
    app()->forgetInstance(ScheduledTaskSensor::class);
});

describe('channel resolution', function () {
    it('does not log when channel is null', function () {
        config(['observability-log.schedule.channel' => null]);
        Log::shouldReceive('channel')->never();
        Log::shouldReceive('stack')->never();

        $task = scheduledEvent();
        ScheduledTaskSensor::recordStarting(new ScheduledTaskStarting($task));
        ScheduledTaskSensor::recordFinished(new ScheduledTaskFinished($task, 0.5));
        ScheduledTaskSensor::recordFailed(new ScheduledTaskFailed($task, new RuntimeException('boom')));
        ScheduledTaskSensor::recordSkipped(new ScheduledTaskSkipped($task));
    });
});

describe('finished event', function () {
    it('emits status=success with task description, expression, and timing', function () {
        config(['observability-log.schedule.channel' => 'test']);

        $task = scheduledEvent('php artisan inspire', '0 12 * * *', 'Daily inspiration');

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(function (string $level, string $message, array $context) {
                return $level === 'info'
                    && $message === 'schedule.task'
                    && $context['task'] === 'Daily inspiration'
                    && $context['expression'] === '0 12 * * *'
                    && $context['status'] === 'success'
                    && is_float($context['duration_ms'])
                    && ! array_key_exists('exception', $context);
            });

        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        ScheduledTaskSensor::recordStarting(new ScheduledTaskStarting($task));
        ScheduledTaskSensor::recordFinished(new ScheduledTaskFinished($task, 0.5));
    });

    it('falls back to the command when no description is set', function () {
        config(['observability-log.schedule.channel' => 'test']);

        $task = scheduledEvent('php artisan inspire');

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn ($level, $message, $context) => is_string($context['task']) && str_contains($context['task'], 'inspire'));

        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        ScheduledTaskSensor::recordStarting(new ScheduledTaskStarting($task));
        ScheduledTaskSensor::recordFinished(new ScheduledTaskFinished($task, 0.1));
    });

    it('skips emission when no Starting fired first', function () {
        config(['observability-log.schedule.channel' => 'test']);
        Log::shouldReceive('channel')->never();

        ScheduledTaskSensor::recordFinished(new ScheduledTaskFinished(scheduledEvent(), 0.1));
    });
});

describe('failed event', function () {
    it('emits status=failed with exception fields', function () {
        config(['observability-log.schedule.channel' => 'test']);

        $task = scheduledEvent();
        $exception = new RuntimeException('boom', 42);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(function ($level, $message, $context) {
                return $context['status'] === 'failed'
                    && ($context['exception']['class'] ?? null) === RuntimeException::class
                    && ($context['exception']['message'] ?? null) === 'boom'
                    && ($context['exception']['code'] ?? null) === 42;
            });

        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        ScheduledTaskSensor::recordStarting(new ScheduledTaskStarting($task));
        ScheduledTaskSensor::recordFailed(new ScheduledTaskFailed($task, $exception));
    });
});

describe('skipped event', function () {
    it('emits status=skipped without preceding Starting', function () {
        config(['observability-log.schedule.channel' => 'test']);

        $task = scheduledEvent('php artisan inspire', '* * * * *', 'Inspire skipped');

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(function ($level, $message, $context) {
                return $context['status'] === 'skipped'
                    && $context['task'] === 'Inspire skipped'
                    && ! array_key_exists('duration_ms', $context)
                    && ! array_key_exists('memory_peak_mb', $context);
            });

        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        ScheduledTaskSensor::recordSkipped(new ScheduledTaskSkipped($task));
    });
});

describe('per-task state', function () {
    it('routes terminal events to the matching task by hash', function () {
        config(['observability-log.schedule.channel' => 'test']);

        $taskA = scheduledEvent('php artisan a', '* * * * *', 'task-a');
        $taskB = scheduledEvent('php artisan b', '* * * * *', 'task-b');

        $captured = [];
        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->twice()
            ->andReturnUsing(function ($level, $message, $context) use (&$captured) {
                $captured[] = $context;
            });

        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        ScheduledTaskSensor::recordStarting(new ScheduledTaskStarting($taskA));
        ScheduledTaskSensor::recordStarting(new ScheduledTaskStarting($taskB));
        ScheduledTaskSensor::recordFinished(new ScheduledTaskFinished($taskA, 0.1));
        ScheduledTaskSensor::recordFailed(new ScheduledTaskFailed($taskB, new RuntimeException('b broke')));

        expect($captured[0]['task'])->toBe('task-a');
        expect($captured[0]['status'])->toBe('success');
        expect($captured[1]['task'])->toBe('task-b');
        expect($captured[1]['status'])->toBe('failed');
    });
});

describe('trace_id', function () {
    it('is emitted from Context when set', function () {
        config(['observability-log.schedule.channel' => 'test']);
        Context::add('trace_id', 'sched-tid');

        $task = scheduledEvent();
        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn ($level, $message, $context) => ($context['trace_id'] ?? null) === 'sched-tid');

        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        ScheduledTaskSensor::recordStarting(new ScheduledTaskStarting($task));
        ScheduledTaskSensor::recordFinished(new ScheduledTaskFinished($task, 0.1));
    });
});

describe('using callback', function () {
    it('replaces the default entry', function () {
        config(['observability-log.schedule.channel' => 'test']);

        ScheduledTaskSensor::using(function ($event, array $measurements) {
            return [
                'only_this' => true,
                'event_class' => get_class($event),
                'duration' => $measurements['duration_ms'] ?? null,
            ];
        });

        $task = scheduledEvent();
        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(function ($level, $message, $context) {
                return $context['only_this'] === true
                    && $context['event_class'] === ScheduledTaskFinished::class
                    && is_float($context['duration'])
                    && ! array_key_exists('expression', $context);
            });

        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        ScheduledTaskSensor::recordStarting(new ScheduledTaskStarting($task));
        ScheduledTaskSensor::recordFinished(new ScheduledTaskFinished($task, 0.1));
    });
});

describe('extend callback', function () {
    it('adds fields after using runs', function () {
        config(['observability-log.schedule.channel' => 'test']);

        ScheduledTaskSensor::extend(function ($event, array $entry) {
            $entry['env'] = 'production';

            return $entry;
        });

        $task = scheduledEvent();
        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn ($level, $message, $context) => ($context['env'] ?? null) === 'production' && $context['status'] === 'success');

        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        ScheduledTaskSensor::recordStarting(new ScheduledTaskStarting($task));
        ScheduledTaskSensor::recordFinished(new ScheduledTaskFinished($task, 0.1));
    });
});

describe('message callback', function () {
    it('uses the configured message by default', function () {
        config(['observability-log.schedule.channel' => 'test']);

        $task = scheduledEvent();
        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn ($level, $message, $context) => $message === 'schedule.task');

        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        ScheduledTaskSensor::recordStarting(new ScheduledTaskStarting($task));
        ScheduledTaskSensor::recordFinished(new ScheduledTaskFinished($task, 0.1));
    });

    it('uses a callback when set', function () {
        config(['observability-log.schedule.channel' => 'test']);

        ScheduledTaskSensor::message(fn ($event) => $event instanceof ScheduledTaskSkipped ? 'schedule.skipped' : 'schedule.ran');

        $task = scheduledEvent();
        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->twice()
            ->andReturnUsing(function ($level, $message, $context) {
                if ($context['status'] === 'skipped') {
                    expect($message)->toBe('schedule.skipped');
                } else {
                    expect($message)->toBe('schedule.ran');
                }
            });

        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        ScheduledTaskSensor::recordSkipped(new ScheduledTaskSkipped($task));

        $taskRan = scheduledEvent();
        ScheduledTaskSensor::recordStarting(new ScheduledTaskStarting($taskRan));
        ScheduledTaskSensor::recordFinished(new ScheduledTaskFinished($taskRan, 0.1));
    });
});

describe('error handling', function () {
    it('swallows logger errors and resets state', function () {
        config(['observability-log.schedule.channel' => 'broken']);

        Log::shouldReceive('channel')->with('broken')->andThrow(new RuntimeException('log broken'));
        Log::shouldReceive('error')->atLeast()->once();

        $task = scheduledEvent();
        ScheduledTaskSensor::recordStarting(new ScheduledTaskStarting($task));
        ScheduledTaskSensor::recordFinished(new ScheduledTaskFinished($task, 0.1));

        $instance = app(ScheduledTaskSensor::class);
        expect((function () {
            return $this->tasks;
        })->call($instance))->toBe([]);
    });
});
