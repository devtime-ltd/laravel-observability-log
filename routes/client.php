<?php

use DevtimeLtd\LaravelObservabilityLog\Http\ClientIngestController;
use Illuminate\Support\Facades\Route;

$middleware = config('observability-log.client.middleware', ['throttle:60,1']);

Route::post(
    config('observability-log.client.path', '_observability'),
    ClientIngestController::class
)
    ->middleware(is_array($middleware) || is_string($middleware) ? $middleware : [])
    ->name('observability-log.client');
