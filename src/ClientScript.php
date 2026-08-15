<?php

namespace DevtimeLtd\LaravelObservabilityLog;

use DevtimeLtd\LaravelObservabilityLog\Support\RequestContext;
use Throwable;

/**
 * Renders the browser agent for the @observability directive. The script
 * is inlined rather than served as an asset: one fewer request, no
 * cache-busting, and nothing to publish on upgrade.
 */
class ClientScript
{
    private static ?string $source = null;

    /**
     * @param  array<string, mixed>  $options  nonce: CSP nonce for the script tag.
     *                                         trace_id: override the resolved trace id.
     */
    public static function render(array $options = []): string
    {
        if (! ClientSensor::enabled()) {
            return '';
        }

        $source = self::source();

        if ($source === null) {
            return '';
        }

        $config = json_encode(
            self::config($options),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
        );

        if ($config === false) {
            return '';
        }

        $nonce = isset($options['nonce']) && is_string($options['nonce'])
            ? ' nonce="'.htmlspecialchars($options['nonce'], ENT_QUOTES, 'UTF-8').'"'
            : '';

        return '<script'.$nonce.'>window.__observability='.$config.';'.$source.'</script>';
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private static function config(array $options): array
    {
        $traceId = $options['trace_id'] ?? RequestContext::traceId(request());

        return [
            'endpoint' => '/'.ltrim((string) config('observability-log.client.path', '_observability'), '/'),
            'collect' => ClientSensor::collecting(),
            'sample' => self::sampleRate(),
            'trace_id' => is_string($traceId) ? $traceId : null,
        ];
    }

    private static function sampleRate(): float
    {
        $value = config('observability-log.client.sample_rate', 1.0);

        if (! is_numeric($value)) {
            return 1.0;
        }

        return max(0.0, min(1.0, (float) $value));
    }

    private static function source(): ?string
    {
        if (self::$source !== null) {
            return self::$source;
        }

        try {
            $path = __DIR__.'/../resources/client.min.js';
            $contents = is_file($path) ? file_get_contents($path) : false;
        } catch (Throwable) {
            return null;
        }

        if ($contents === false) {
            return null;
        }

        return self::$source = trim($contents);
    }

    /** Test seam: forget the cached script source. */
    public static function flushSource(): void
    {
        self::$source = null;
    }
}
