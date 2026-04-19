<?php

namespace DevtimeLtd\LaravelObservabilityLog;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\ServiceProvider;
use Throwable;

class ObservabilityLogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/observability-log.php', 'observability-log');
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
    }
}
