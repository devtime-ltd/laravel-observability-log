<?php

namespace DevtimeLtd\LaravelObservabilityLog;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Throwable;

class ObservabilityLogServiceProvider extends ServiceProvider
{
    public const QUERY_LISTENER_BINDING = 'devtime-ltd.observability-log.query-listener';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/observability-log.php', 'observability-log');

        $this->app->singleton(JobSensor::class);
        $this->app->singleton(CommandSensor::class);
        $this->app->singleton(ScheduledTaskSensor::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/observability-log.php' => config_path('observability-log.php'),
        ], 'observability-log');

        $this->app->afterResolving(ExceptionHandler::class, function ($handler) {
            if (method_exists($handler, 'reportable')) {
                $handler->reportable(function (Throwable $e) {
                    ExceptionSensor::report($e);
                });
            }
        });

        Event::listen(JobQueued::class, [JobSensor::class, 'recordQueued']);
        Event::listen(JobProcessing::class, [JobSensor::class, 'recordProcessing']);
        Event::listen(JobProcessed::class, [JobSensor::class, 'recordProcessed']);
        Event::listen(JobExceptionOccurred::class, [JobSensor::class, 'recordExceptionOccurred']);
        Event::listen(JobFailed::class, [JobSensor::class, 'recordFailed']);

        Event::listen(CommandStarting::class, [CommandSensor::class, 'recordStarting']);
        Event::listen(CommandFinished::class, [CommandSensor::class, 'recordFinished']);

        Event::listen(ScheduledTaskStarting::class, [ScheduledTaskSensor::class, 'recordStarting']);
        Event::listen(ScheduledTaskFinished::class, [ScheduledTaskSensor::class, 'recordFinished']);
        Event::listen(ScheduledBackgroundTaskFinished::class, [ScheduledTaskSensor::class, 'recordBackgroundFinished']);
        Event::listen(ScheduledTaskFailed::class, [ScheduledTaskSensor::class, 'recordFailed']);
        Event::listen(ScheduledTaskSkipped::class, [ScheduledTaskSensor::class, 'recordSkipped']);

        $this->registerSharedQueryListener();
    }

    private function registerSharedQueryListener(): void
    {
        if ($this->app->bound(self::QUERY_LISTENER_BINDING)) {
            return;
        }

        DB::listen(function (QueryExecuted $query) {
            RequestSensor::recordQuery($query);
            JobSensor::recordQuery($query);
            CommandSensor::recordQuery($query);
            ScheduledTaskSensor::recordQuery($query);
        });

        $this->app->instance(self::QUERY_LISTENER_BINDING, true);
    }
}
