<?php

namespace App\Saga\Infrastructure;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class Outbox
{
    public function record(
        string $eventType,
        string $routingKey,
        string $aggregateType,
        string|int $aggregateId,
        array $payload,
        ?string $correlationId = null,
        ?string $eventId = null,
    ): string {
        $eventId ??= (string) Str::uuid();
        $payload['event_id'] = $eventId;
        $payload['event_type'] = $eventType;
        $payload['correlation_id'] = $correlationId;
        $payload['occurred_at'] = now()->toISOString();

        DB::table('integration_outbox')->insert([
            'id' => $eventId,
            'event_type' => $eventType,
            'routing_key' => $routingKey,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => (string) $aggregateId,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'correlation_id' => $correlationId,
            'available_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $eventId;
    }
}
