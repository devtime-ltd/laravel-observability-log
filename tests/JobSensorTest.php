<?php

use DevtimeLtd\LaravelObservabilityLog\JobSensor;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    JobSensor::using(null);
    JobSensor::extend(null);
    JobSensor::message(null);
    Context::flush();
    app()->forgetInstance(JobSensor::class);
    app()->forgetInstance(JobSensor::QUERY_LISTENER_BINDING);
});

describe('channel resolution', function () {
    it('does not log when channel is null', function () {
        config(['observability-log.jobs.channel' => null]);
        Log::shouldReceive('channel')->never();
        Log::shouldReceive('stack')->never();

        JobSensor::recordQueued(new JobQueued('redis', 'default', 'id', new stdClass, '{}', null));
        JobSensor::recordProcessing(new JobProcessing('redis', fakeJob()));
        JobSensor::recordProcessed(new JobProcessed('redis', fakeJob()));
        JobSensor::recordExceptionOccurred(new JobExceptionOccurred('redis', fakeJob(), new RuntimeException('boom')));
        JobSensor::recordFailed(new JobFailed('redis', fakeJob(), new RuntimeException('boom')));
    });

    it('does not log when channel is an empty string', function () {
        config(['observability-log.jobs.channel' => '']);
        Log::shouldReceive('channel')->never();

        JobSensor::recordQueued(new JobQueued('redis', 'default', 'id', new stdClass, '{}', null));
    });

    it('uses Log::stack when multiple channels are configured', function () {
        config(['observability-log.jobs.channel' => 'a,b']);

        $stack = Mockery::mock();
        $stack->shouldReceive('log')->once()->with('info', 'job.queued', Mockery::type('array'));
        Log::shouldReceive('stack')->with(['a', 'b'])->andReturn($stack);

        JobSensor::recordQueued(new JobQueued('redis', 'default', 'id', new stdClass, '{}', null));
    });

    it('trims whitespace and drops empty entries in the channel list', function () {
        config(['observability-log.jobs.channel' => 'a, b ,']);

        $stack = Mockery::mock();
        $stack->shouldReceive('log')->once();
        Log::shouldReceive('stack')->with(['a', 'b'])->andReturn($stack);

        JobSensor::recordQueued(new JobQueued('redis', 'default', 'id', new stdClass, '{}', null));
    });

    it('short-circuits all entry points when the channel list normalises to nothing', function () {
        config(['observability-log.jobs.channel' => ' , ,']);
        Log::shouldReceive('channel')->never();
        Log::shouldReceive('stack')->never();

        JobSensor::recordQueued(new JobQueued('redis', 'default', 'id', new stdClass, '{}', null));
        JobSensor::recordProcessing(new JobProcessing('redis', fakeJob()));
        JobSensor::recordProcessed(new JobProcessed('redis', fakeJob()));
    });
});

describe('queued event', function () {
    it('emits class, queue, connection, job_id, payload_size', function () {
        config(['observability-log.jobs.channel' => 'test']);

        $job = new stdClass;
        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(function (string $level, string $message, array $context) {
                return $level === 'info'
                    && $message === 'job.queued'
                    && $context['class'] === 'stdClass'
                    && $context['queue'] === 'default'
                    && $context['connection'] === 'redis'
                    && $context['job_id'] === 'job-42'
                    && $context['payload_size'] === 11;
            });

        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        JobSensor::recordQueued(new JobQueued('redis', 'default', 'job-42', $job, '{"foo":"a"}', null));
    });

    it('uses the job string when a string class is queued', function () {
        config(['observability-log.jobs.channel' => 'test']);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn ($level, $message, $context) => $context['class'] === 'App\\Jobs\\StringJob');

        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        JobSensor::recordQueued(new JobQueued('redis', 'default', 'id', 'App\\Jobs\\StringJob', '{}', null));
    });

    it('falls back to "unknown" when the job is null or empty', function () {
        config(['observability-log.jobs.channel' => 'test']);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn ($level, $message, $context) => $context['class'] === 'unknown');

        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        JobSensor::recordQueued(new JobQueued('redis', 'default', 'id', '', '{}', null));
    });

    it('includes delay when greater than zero', function () {
        config(['observability-log.jobs.channel' => 'test']);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn ($level, $message, $context) => ($context['delay'] ?? null) === 60);

        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        JobSensor::recordQueued(new JobQueued('redis', 'default', 'id', new stdClass, '{}', 60));
    });

    it('omits delay when zero or null', function () {
        config(['observability-log.jobs.channel' => 'test']);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->twice()
            ->withArgs(fn ($level, $message, $context) => ! array_key_exists('delay', $context));

        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        JobSensor::recordQueued(new JobQueued('redis', 'default', 'id', new stdClass, '{}', null));
        JobSensor::recordQueued(new JobQueued('redis', 'default', 'id', new stdClass, '{}', 0));
    });

    it('emits trace_id from Context', function () {
        config(['observability-log.jobs.channel' => 'test']);
        Context::add('trace_id', 'ctx-77');

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn ($level, $message, $context) => ($context['trace_id'] ?? null) === 'ctx-77');

        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        JobSensor::recordQueued(new JobQueued('redis', 'default', 'id', new stdClass, '{}', null));
    });

    it('omits trace_id when Context has none', function () {
        config(['observability-log.jobs.channel' => 'test']);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn ($level, $message, $context) => ! array_key_exists('trace_id', $context));

        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        JobSensor::recordQueued(new JobQueued('redis', 'default', 'id', new stdClass, '{}', null));
    });
});

describe('attempt class resolution', function () {
    it('falls back to getName when resolveName is empty', function () {
        config(['observability-log.jobs.channel' => 'test']);

        $job = fakeJob([
            'resolveName' => '',
            'getName' => 'App\\Jobs\\Fallback',
        ]);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn ($level, $message, $context) => $context['class'] === 'App\\Jobs\\Fallback');

        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        JobSensor::recordProcessing(new JobProcessing('redis', $job));
        JobSensor::recordProcessed(new JobProcessed('redis', $job));
    });

    it('emits class as null when both resolveName and getName are empty', function () {
        config(['observability-log.jobs.channel' => 'test']);

        $job = fakeJob([
            'resolveName' => '',
            'getName' => '',
        ]);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn ($level, $message, $context) => array_key_exists('class', $context) && $context['class'] === null);

        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        JobSensor::recordProcessing(new JobProcessing('redis', $job));
        JobSensor::recordProcessed(new JobProcessed('redis', $job));
    });

    it('coerces integer job ids to strings', function () {
        config(['observability-log.jobs.channel' => 'test']);

        $job = fakeJob(['getJobId' => 42]);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn ($level, $message, $context) => $context['job_id'] === '42');

        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        JobSensor::recordProcessing(new JobProcessing('redis', $job));
        JobSensor::recordProcessed(new JobProcessed('redis', $job));
    });

    it('emits null job_id when the queue driver does not return one', function () {
        config(['observability-log.jobs.channel' => 'test']);

        $job = fakeJob(['getJobId' => null]);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn ($level, $message, $context) => array_key_exists('job_id', $context) && $context['job_id'] === null);

        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        JobSensor::recordProcessing(new JobProcessing('redis', $job));
        JobSensor::recordProcessed(new JobProcessed('redis', $job));
    });
});

describe('processed event', function () {
    it('emits status=processed with class, queue, attempt, duration', function () {
        config(['observability-log.jobs.channel' => 'test']);

        $job = fakeJob();
        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(function ($level, $message, $context) {
                return $level === 'info'
                    && $message === 'job.attempt'
                    && $context['status'] === 'processed'
                    && $context['class'] === 'App\\Jobs\\SendEmail'
                    && $context['queue'] === 'default'
                    && $context['connection'] === 'redis'
                    && $context['job_id'] === 'job-1'
                    && $context['attempt'] === 1
                    && $context['max_tries'] === 3
                    && is_float($context['duration_ms'])
                    && is_float($context['memory_peak_mb'])
                    && ! array_key_exists('exception', $context);
            });

        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        JobSensor::recordProcessing(new JobProcessing('redis', $job));
        JobSensor::recordProcessed(new JobProcessed('redis', $job));
    });

    it('omits max_tries when the job returns null', function () {
        config(['observability-log.jobs.channel' => 'test']);

        $job = fakeJob(['maxTries' => null]);
        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn ($level, $message, $context) => ! array_key_exists('max_tries', $context));

        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        JobSensor::recordProcessing(new JobProcessing('redis', $job));
        JobSensor::recordProcessed(new JobProcessed('redis', $job));
    });

    it('skips emission when no JobProcessing fired first', function () {
        config(['observability-log.jobs.channel' => 'test']);
        Log::shouldReceive('channel')->never();

        JobSensor::recordProcessed(new JobProcessed('redis', fakeJob()));
    });

    it('resets state cleanly between attempts', function () {
        config(['observability-log.jobs.channel' => 'test']);

        $captured = [];
        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->twice()
            ->andReturnUsing(function ($level, $message, $context) use (&$captured) {
                $captured[] = $context;
            });

        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        $job1 = fakeJob(['getJobId' => 'one']);
        $job2 = fakeJob(['getJobId' => 'two']);

        JobSensor::recordProcessing(new JobProcessing('redis', $job1));
        JobSensor::recordProcessed(new JobProcessed('redis', $job1));
        JobSensor::recordProcessing(new JobProcessing('redis', $job2));
        JobSensor::recordProcessed(new JobProcessed('redis', $job2));

        expect($captured[0]['job_id'])->toBe('one');
        expect($captured[1]['job_id'])->toBe('two');
    });
});

describe('failed events', function () {
    it('emits status=failed with exception fields on JobExceptionOccurred', function () {
        config(['observability-log.jobs.channel' => 'test']);

        $job = fakeJob();
        $exception = new RuntimeException('boom', 99);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(function ($level, $message, $context) {
                return $context['status'] === 'failed'
                    && ($context['exception']['class'] ?? null) === RuntimeException::class
                    && ($context['exception']['message'] ?? null) === 'boom'
                    && ($context['exception']['code'] ?? null) === 99
                    && is_int($context['exception']['line'])
                    && is_string($context['exception']['file']);
            });

        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        JobSensor::recordProcessing(new JobProcessing('redis', $job));
        JobSensor::recordExceptionOccurred(new JobExceptionOccurred('redis', $job, $exception));
    });

    it('emits on JobFailed when nothing emitted earlier', function () {
        config(['observability-log.jobs.channel' => 'test']);

        $job = fakeJob();
        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn ($level, $message, $context) => $context['status'] === 'failed');

        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        JobSensor::recordProcessing(new JobProcessing('redis', $job));
        JobSensor::recordFailed(new JobFailed('redis', $job, new RuntimeException('boom')));
    });

    it('does not double-emit when JobExceptionOccurred is followed by JobFailed', function () {
        config(['observability-log.jobs.channel' => 'test']);

        $job = fakeJob();
        $channel = Mockery::mock();
        $channel->shouldReceive('log')->once();
        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        $exception = new RuntimeException('boom');

        JobSensor::recordProcessing(new JobProcessing('redis', $job));
        JobSensor::recordExceptionOccurred(new JobExceptionOccurred('redis', $job, $exception));
        JobSensor::recordFailed(new JobFailed('redis', $job, $exception));
    });

    it('does not emit JobProcessed after a manual fail() inside fire()', function () {
        config(['observability-log.jobs.channel' => 'test']);

        $job = fakeJob();
        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn ($level, $message, $context) => $context['status'] === 'failed');

        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        JobSensor::recordProcessing(new JobProcessing('redis', $job));
        JobSensor::recordFailed(new JobFailed('redis', $job, new RuntimeException('manual')));
        JobSensor::recordProcessed(new JobProcessed('redis', $job));
    });
});

describe('query tracking', function () {
    it('omits db_* fields when collect_queries is disabled', function () {
        config([
            'observability-log.jobs.channel' => 'test',
            'observability-log.jobs.collect_queries' => false,
        ]);

        $job = fakeJob();
        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn ($level, $message, $context) =>
                ! array_key_exists('db_query_count', $context)
                && ! array_key_exists('db_query_total_ms', $context)
                && ! array_key_exists('db_slow_queries', $context)
            );

        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        JobSensor::recordProcessing(new JobProcessing('redis', $job));
        JobSensor::recordProcessed(new JobProcessed('redis', $job));
    });

    it('includes zeroed db fields when no queries ran but collection is on', function () {
        config([
            'observability-log.jobs.channel' => 'test',
            'observability-log.jobs.collect_queries' => true,
        ]);

        $job = fakeJob();
        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn ($level, $message, $context) =>
                $context['db_query_count'] === 0
                && $context['db_query_total_ms'] === 0.0
                && ! array_key_exists('db_slow_queries', $context)
            );

        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        JobSensor::recordProcessing(new JobProcessing('redis', $job));
        JobSensor::recordProcessed(new JobProcessed('redis', $job));
    });

    it('does not register the DB listener when channel is empty', function () {
        config(['observability-log.jobs.channel' => null]);

        JobSensor::recordProcessing(new JobProcessing('redis', fakeJob()));

        expect(app()->bound(JobSensor::QUERY_LISTENER_BINDING))->toBeFalse();
    });
});

describe('trace_id', function () {
    it('is emitted from Context for attempt entries', function () {
        config(['observability-log.jobs.channel' => 'test']);
        Context::add('trace_id', 'attempt-tid');

        $job = fakeJob();
        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn ($level, $message, $context) => ($context['trace_id'] ?? null) === 'attempt-tid');

        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        JobSensor::recordProcessing(new JobProcessing('redis', $job));
        JobSensor::recordProcessed(new JobProcessed('redis', $job));
    });

    it('is omitted when Context is empty', function () {
        config(['observability-log.jobs.channel' => 'test']);

        $job = fakeJob();
        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn ($level, $message, $context) => ! array_key_exists('trace_id', $context));

        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        JobSensor::recordProcessing(new JobProcessing('redis', $job));
        JobSensor::recordProcessed(new JobProcessed('redis', $job));
    });
});

describe('using callback', function () {
    it('replaces the default entry on attempt events', function () {
        config(['observability-log.jobs.channel' => 'test']);

        JobSensor::using(function ($event, $measurements) {
            return [
                'only_this' => true,
                'event' => get_class($event),
                'duration' => $measurements['duration_ms'] ?? null,
            ];
        });

        $job = fakeJob();
        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(function ($level, $message, $context) {
                return $context['only_this'] === true
                    && $context['event'] === JobProcessed::class
                    && is_float($context['duration'])
                    && ! array_key_exists('class', $context);
            });

        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        JobSensor::recordProcessing(new JobProcessing('redis', $job));
        JobSensor::recordProcessed(new JobProcessed('redis', $job));
    });

    it('replaces the default entry on queued events with empty measurements', function () {
        config(['observability-log.jobs.channel' => 'test']);

        JobSensor::using(function ($event, $measurements) {
            return [
                'replaced' => true,
                'measurements_empty' => $measurements === [],
                'event_class' => get_class($event),
            ];
        });

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn ($level, $message, $context) =>
                $context['replaced'] === true
                && $context['measurements_empty'] === true
                && $context['event_class'] === JobQueued::class
            );

        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        JobSensor::recordQueued(new JobQueued('redis', 'default', 'id', new stdClass, '{}', null));
    });

    it('falls back to default when callback throws', function () {
        config(['observability-log.jobs.channel' => 'test']);

        JobSensor::using(function () {
            throw new RuntimeException('using broke');
        });

        $job = fakeJob();
        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn ($level, $message, $context) => ($context['class'] ?? null) === 'App\\Jobs\\SendEmail');

        Log::shouldReceive('channel')->with('test')->andReturn($channel);
        Log::shouldReceive('error')->once()->with(Mockery::pattern('/using callback threw.*using broke/'));

        JobSensor::recordProcessing(new JobProcessing('redis', $job));
        JobSensor::recordProcessed(new JobProcessed('redis', $job));
    });
});

describe('extend callback', function () {
    it('adds fields after using runs', function () {
        config(['observability-log.jobs.channel' => 'test']);

        JobSensor::using(fn ($event, $measurements) => ['base' => true]);
        JobSensor::extend(function ($event, $entry) {
            $entry['added'] = 'yes';

            return $entry;
        });

        $job = fakeJob();
        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn ($level, $message, $context) =>
                $context['base'] === true && $context['added'] === 'yes'
            );

        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        JobSensor::recordProcessing(new JobProcessing('redis', $job));
        JobSensor::recordProcessed(new JobProcessed('redis', $job));
    });

    it('keeps the previous entry when callback throws', function () {
        config(['observability-log.jobs.channel' => 'test']);

        JobSensor::extend(function () {
            throw new RuntimeException('extend broke');
        });

        $job = fakeJob();
        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn ($level, $message, $context) => ($context['class'] ?? null) === 'App\\Jobs\\SendEmail');

        Log::shouldReceive('channel')->with('test')->andReturn($channel);
        Log::shouldReceive('error')->once()->with(Mockery::pattern('/extend callback threw.*extend broke/'));

        JobSensor::recordProcessing(new JobProcessing('redis', $job));
        JobSensor::recordProcessed(new JobProcessed('redis', $job));
    });
});

describe('message callback', function () {
    it('uses queued_message default for JobQueued', function () {
        config(['observability-log.jobs.channel' => 'test']);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn ($level, $message, $context) => $message === 'job.queued');

        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        JobSensor::recordQueued(new JobQueued('redis', 'default', 'id', new stdClass, '{}', null));
    });

    it('uses attempt_message default for processing/processed', function () {
        config(['observability-log.jobs.channel' => 'test']);

        $job = fakeJob();
        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn ($level, $message, $context) => $message === 'job.attempt');

        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        JobSensor::recordProcessing(new JobProcessing('redis', $job));
        JobSensor::recordProcessed(new JobProcessed('redis', $job));
    });

    it('honours the configured queued_message', function () {
        config([
            'observability-log.jobs.channel' => 'test',
            'observability-log.jobs.queued_message' => 'queue.dispatched',
        ]);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn ($level, $message, $context) => $message === 'queue.dispatched');

        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        JobSensor::recordQueued(new JobQueued('redis', 'default', 'id', new stdClass, '{}', null));
    });

    it('uses a callback when set, receiving the event', function () {
        config(['observability-log.jobs.channel' => 'test']);

        JobSensor::message(fn ($event) => $event instanceof JobQueued ? 'q.event' : 'a.event');

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->twice()
            ->andReturnUsing(function ($level, $message, $context) {
                if ($context['status'] ?? null) {
                    expect($message)->toBe('a.event');
                } else {
                    expect($message)->toBe('q.event');
                }
            });

        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        JobSensor::recordQueued(new JobQueued('redis', 'default', 'id', new stdClass, '{}', null));

        $job = fakeJob();
        JobSensor::recordProcessing(new JobProcessing('redis', $job));
        JobSensor::recordProcessed(new JobProcessed('redis', $job));
    });

    it('accepts a fixed string', function () {
        config(['observability-log.jobs.channel' => 'test']);
        JobSensor::message('fixed.message');

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn ($level, $message, $context) => $message === 'fixed.message');

        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        JobSensor::recordQueued(new JobQueued('redis', 'default', 'id', new stdClass, '{}', null));
    });

    it('falls back to config message when callback throws', function () {
        config(['observability-log.jobs.channel' => 'test']);

        JobSensor::message(function () {
            throw new RuntimeException('msg broke');
        });

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn ($level, $message, $context) => $message === 'job.queued');

        Log::shouldReceive('channel')->with('test')->andReturn($channel);
        Log::shouldReceive('error')->once()->with(Mockery::pattern('/message callback threw.*msg broke/'));

        JobSensor::recordQueued(new JobQueued('redis', 'default', 'id', new stdClass, '{}', null));
    });
});

describe('level config', function () {
    it('uses level from config', function () {
        config([
            'observability-log.jobs.channel' => 'test',
            'observability-log.jobs.level' => 'warning',
        ]);

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(fn ($level, $message, $context) => $level === 'warning');

        Log::shouldReceive('channel')->with('test')->andReturn($channel);

        JobSensor::recordQueued(new JobQueued('redis', 'default', 'id', new stdClass, '{}', null));
    });
});

describe('error handling', function () {
    it('swallows logger errors during queued emission', function () {
        config(['observability-log.jobs.channel' => 'broken']);

        Log::shouldReceive('channel')->with('broken')->andThrow(new RuntimeException('log broken'));
        Log::shouldReceive('error')->once()->with(Mockery::pattern('/log broken/'));

        JobSensor::recordQueued(new JobQueued('redis', 'default', 'id', new stdClass, '{}', null));

        expect(true)->toBeTrue();
    });

    it('swallows logger errors during attempt emission and resets state', function () {
        config(['observability-log.jobs.channel' => 'broken']);

        Log::shouldReceive('channel')->with('broken')->andThrow(new RuntimeException('log broken'));
        Log::shouldReceive('error')->atLeast()->once();

        $job = fakeJob();
        JobSensor::recordProcessing(new JobProcessing('redis', $job));
        JobSensor::recordProcessed(new JobProcessed('redis', $job));

        // After failure, the next attempt cycle should still work
        $instance = app(JobSensor::class);
        expect((function () {
            return $this->emitted;
        })->call($instance))->toBeTrue();
    });
});
