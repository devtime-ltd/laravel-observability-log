<?php

namespace DevtimeLtd\LaravelObservabilityLog;

use DevtimeLtd\LaravelObservabilityLog\Support\RequestContext;
use Illuminate\Support\Str;
use Throwable;

/**
 * Renders the browser agent for the @observabilityClient directive. The script
 * is inlined rather than served as an asset: one fewer request, no
 * cache-busting, and nothing to publish on upgrade.
 */
class ClientScript
{
    private static ?string $source = null;

    /**
     * @param  array<string, mixed>  $options  nonce: CSP nonce for the script tag.
     *                                         trace_id: override the resolved trace id.
     *                                         client_id: false to keep no id in the
     *                                         browser's storage and remove any kept
     *                                         there, or the app's own.
     *                                         client_instance_id, visit_id: override
     *                                         the ids minted for this page.
     *                                         cacheable: the response may be replayed
     *                                         to other browsers, so nothing of this
     *                                         request's is written into it.
     *                                         inertia: true to follow Inertia visits, or
     *                                         ['trace_id' => the page prop holding each
     *                                         visit's trace id].
     *                                         release: the build being served.
     *                                         view: what the page shows, such as a route name.
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
        $traceId = ($options['cacheable'] ?? false)
            ? null
            : $options['trace_id'] ?? RequestContext::traceId(request());

        $path = ltrim((string) config('observability-log.client.path', '_observability'), '/');

        return [
            // Host-relative, so a multi-domain app posts to whichever host
            // served the page, but prefixed for an app mounted in a
            // subdirectory.
            'endpoint' => rtrim(request()?->getBaseUrl() ?? '', '/').'/'.$path,
            'collect' => ClientSensor::collecting(),
            'sample' => self::sampleRate(),
            'max_bytes' => (int) config('observability-log.client.max_body_bytes', 8192),
            'trace_id' => is_string($traceId) ? $traceId : null,
            ...self::ids($options),
            'headers' => RequestContext::clientIdHeaders(),
            'inertia' => self::inertia($options['inertia'] ?? null),
            'release' => self::text($options['release'] ?? config('observability-log.client.release')),
            'view' => self::text($options['view'] ?? null),
        ];
    }

    /**
     * The instance and its first visit are minted here rather than in the
     * browser so that the request rendering the page carries them too, not
     * only the requests the page goes on to make. A cacheable page gets
     * none of the three, and the browser mints its own. The client id is
     * only ever the browser's, unless the app supplies one.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, string|false|null>
     */
    private static function ids(array $options): array
    {
        $ids = [];
        $enabled = RequestContext::enabledClientIds();
        $clientId = $options['client_id'] ?? null;

        $cacheable = (bool) ($options['cacheable'] ?? false);

        if ($clientId === false || ! in_array('client_id', $enabled, true)) {
            $ids['client_id'] = false;
        } elseif (! $cacheable && RequestContext::validClientId($clientId) !== null) {
            $ids['client_id'] = $clientId;
        }

        if ($cacheable) {
            return $ids;
        }

        try {
            $known = RequestContext::clientIds(request());

            foreach (array_intersect(['client_instance_id', 'visit_id'], $enabled) as $name) {
                $ids[$name] = RequestContext::validClientId($options[$name] ?? null)
                    ?? $known[$name]
                    ?? (string) Str::uuid();
            }
        } catch (Throwable) {
            return $ids;
        }

        RequestContext::shareClientIds(null, array_filter($ids, 'is_string'));

        return $ids;
    }

    /** @return bool|array{trace_id: string} */
    private static function inertia(mixed $option): bool|array
    {
        if (! is_array($option)) {
            return $option === true;
        }

        $prop = self::text($option['trace_id'] ?? null);

        return $prop === null ? true : ['trace_id' => $prop];
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
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
