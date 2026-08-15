<?php

namespace DevtimeLtd\LaravelObservabilityLog\Http;

use DevtimeLtd\LaravelObservabilityLog\ClientSensor;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The package's only public write endpoint. Everything it accepts is
 * attacker-controlled, so the body is capped before it is decoded, the
 * batch is capped before it is walked, and every field is validated by
 * ClientSensor rather than passed through. It answers 204 whatever it
 * decides, so it cannot be used to probe what an app collects.
 */
class ClientIngestController
{
    public function __invoke(Request $request): Response
    {
        $accepted = new Response('', 204);

        if (! ClientSensor::enabled()) {
            return $accepted;
        }

        $body = $request->getContent();

        if (! is_string($body) || strlen($body) > self::intConfig('max_body_bytes', 8192)) {
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
