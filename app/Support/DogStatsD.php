<?php

namespace App\Support;

final class DogStatsD
{
    public static function metric(string $name, float|int $value, string $type = 'g', array $tags = []): void
    {
        $host = (string) env('DD_AGENT_HOST', '');
        if ($host === '') {
            return;
        }

        $tagString = $tags ? '|#'.implode(',', array_map(
            fn ($key, $tagValue) => $key.':'.str_replace([',', '|', ':'], '_', (string) $tagValue),
            array_keys($tags), array_values($tags)
        )) : '';

        try {
            $socket = @stream_socket_client('udp://'.$host.':'.(int) env('DD_DOGSTATSD_PORT', 8125), $errno, $error, 0.1);
            if ($socket) {
                fwrite($socket, $name.':'.$value.'|'.$type.$tagString);
                fclose($socket);
            }
        } catch (\Throwable) {
            // Telemetry must never affect the request outcome.
        }
    }
}
