<?php

namespace DevtimeLtd\LaravelObservabilityLog;

use Illuminate\Support\ServiceProvider;

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
    }
}
