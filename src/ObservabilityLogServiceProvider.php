<?php

namespace DevtimeLtd\LaravelObservabilityLog;

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
        });

        $this->app->instance(self::QUERY_LISTENER_BINDING, true);
    }
}
