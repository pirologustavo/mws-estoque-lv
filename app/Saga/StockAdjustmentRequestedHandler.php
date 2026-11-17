<?php

namespace App\Saga;

use App\Saga\Infrastructure\Outbox;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class StockAdjustmentRequestedHandler
{
    public function __construct(private readonly StockAdjustmentService $adjustments, private readonly Outbox $outbox) {}

    public function handle(array $event): void
    {
        foreach (['event_id', 'event_type', 'saga_id', 'operation_id', 'osid', 'action', 'items'] as $field) {
            if (! array_key_exists($field, $event) || $event[$field] === null || $event[$field] === '') {
                throw new InvalidArgumentException("Campo obrigatório ausente: {$field}");
            }
        }
        $cancel = $event['event_type'] === 'OSStockAdjustmentCancellationRequested';
        if (! in_array($event['event_type'], ['OSStockAdjustmentRequested', 'OSStockAdjustmentCancellationRequested'], true)
            || ! is_array($event['items']) || ! $event['items'] || ($cancel && empty($event['request_event_id']))) {
            throw new InvalidArgumentException('Evento de ajuste de estoque inválido.');
        }
        foreach ($event['items'] as $item) {
            if (! is_array($item) || (int) ($item['id'] ?? 0) < 1 || (int) ($item['qtd'] ?? 0) === 0) {
                throw new InvalidArgumentException('Item de ajuste inválido.');
            }
        }

        DB::transaction(function () use ($event, $cancel): void {
            $key = $cancel ? $event['request_event_id'] : $event['event_id'];
            $hash = hash('sha256', json_encode([$event['operation_id'], $event['osid'], $event['items']], JSON_THROW_ON_ERROR));
            DB::table('stock_saga_guards')->insertOrIgnore([
                'request_event_id' => $key, 'payload_hash' => $hash,
                'state' => DB::table('stock_operations')->where('idempotency_key', $key)->whereNotNull('response_json')->exists() ? 'APPLIED' : 'NEW',
                'items' => json_encode($event['items'], JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $guard = DB::table('stock_saga_guards')->where('request_event_id', $key)->lockForUpdate()->first();
            if (! hash_equals($guard->payload_hash, $hash)) {
                throw new InvalidArgumentException('Evento reutilizado com outro conteúdo.');
            }
            if (DB::table('integration_inbox')->where('event_id', $event['event_id'])->exists()) {
                // Replaying a request also recovers a result that exhausted broker retries.
                DB::table('integration_outbox')->where('id', $guard->result_event_id)->update([
                    'published_at' => null, 'available_at' => now(),
                ]);

                return;
            }

            $success = false;
            $reason = 'Operação cancelada após falha ou timeout.';
            $state = $guard->state;
            if ($cancel) {
                if ($state === 'APPLIED') {
                    $inverse = array_map(fn ($item) => ['id' => $item['id'], 'qtd' => -$item['qtd']], json_decode($guard->items, true, 512, JSON_THROW_ON_ERROR));
                    // Reverse the committed adjustment before acknowledging cancellation.
                    $this->adjustments->applyWithinTransaction('cancel:'.$key, $inverse);
                }
                $state = 'CANCELLED';
            } elseif ($state !== 'CANCELLED') {
                try {
                    DB::transaction(fn () => $this->adjustments->applyWithinTransaction($key, $event['items']));
                    $success = true;
                    $reason = null;
                    $state = 'APPLIED';
                } catch (StockAdjustmentRejected $exception) {
                    $reason = $exception->getMessage();
                    $state = 'REJECTED';
                }
            }
            DB::table('integration_inbox')->insert([
                'event_id' => $event['event_id'], 'event_type' => $event['event_type'], 'processed_at' => now(),
            ]);
            $result = $this->publishResult($event, $key, $success, $reason, $cancel || $state === 'CANCELLED');
            DB::table('stock_saga_guards')->where('request_event_id', $key)->update([
                'state' => $state, 'result_event_id' => $result, 'updated_at' => now(),
            ]);
        });
    }

    private function publishResult(array $request, string $requestId, bool $success, ?string $reason, bool $cancelled): string
    {
        $type = $success ? 'stock.adjustment.succeeded' : 'stock.adjustment.failed';

        return $this->outbox->record($type, $type, 'Estoque', $request['osid'], [
            'saga_id' => $request['saga_id'], 'operation_id' => $request['operation_id'],
            'osid' => $request['osid'], 'action' => $request['action'],
            'request_event_id' => $requestId, 'items' => $request['items'],
            'reason' => $reason, 'cancelled' => $cancelled,
        ], $request['correlation_id'] ?? null);
    }
}
