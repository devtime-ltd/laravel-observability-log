<?php

namespace DevtimeLtd\LaravelObservabilityLog\Http;

use DevtimeLtd\LaravelObservabilityLog\ClientSensor;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The package's only public write endpoint. Everything it accepts is
 * attacker-controlled, so the body is capped before it is decoded, the
 * batch is capped before it is walked, and every field is validated by
 * ClientSensor rather than passed through. Every path through the
 * controller answers 204, so it cannot be used to probe what an app
 * collects; the configured middleware still answers for itself (a
 * throttled request gets the usual 429).
 */
class ClientIngestController
{
    public function __invoke(Request $request): Response
    {
        $accepted = new Response('', 204);

        if (! ClientSensor::enabled()) {
            return $accepted;
        }

        $max = self::intConfig('max_body_bytes', 8192);
        $declared = $request->server('CONTENT_LENGTH');

        // Checked before reading, so an oversized body is refused on its
        // header rather than pulled into memory first.
        if (is_numeric($declared) && (int) $declared > $max) {
            return $accepted;
        }

        $body = $request->getContent();

        if (! is_string($body) || strlen($body) > $max) {
            return $accepted;
        }

        $payload = json_decode($body, true);

        foreach ($this->entries($payload) as $entry) {
            ClientSensor::record($request, $entry);
        }

        return $accepted;
    }

    /** @return list<mixed> */
    private function entries(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        $entries = array_is_list($payload) ? $payload : ($payload['entries'] ?? [$payload]);

        if (! is_array($entries) || ! array_is_list($entries)) {
            return [];
        }

        return array_slice($entries, 0, self::intConfig('max_entries', 20));
    }

    private static function intConfig(string $key, int $default): int
    {
        $value = config('observability-log.client.'.$key, $default);

        return is_int($value) && $value > 0 ? $value : $default;
    }
}
