<?php

namespace Tests\Feature;

use App\Models\Estoque;
use App\Saga\StockAdjustmentRejected;
use App\Saga\StockAdjustmentRequestedHandler;
use App\Saga\StockAdjustmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockSagaTest extends TestCase
{
    use RefreshDatabase;

    public function test_reserve_duplicate_release_and_rejection_are_atomic(): void
    {
        $item = Estoque::create(['descricao' => 'Peça', 'tipo' => 'peca', 'quantidade_atual' => 10, 'valor_venda' => 10, 'ativo' => true]);
        $event = ['event_id' => 'one', 'event_type' => 'OSStockAdjustmentRequested', 'saga_id' => 'saga', 'operation_id' => 'operation', 'osid' => 1, 'action' => 'create', 'items' => [['id' => $item->estoqid, 'qtd' => 2]]];
        $handler = app(StockAdjustmentRequestedHandler::class);
        $handler->handle($event);
        $handler->handle($event);
        $this->assertSame(8, (int) $item->fresh()->quantidade_atual);
        $this->assertDatabaseCount('integration_outbox', 1);
        $event['event_id'] = 'release';
        $event['items'][0]['qtd'] = -2;
        $handler->handle($event);
        $this->assertSame(10, (int) $item->fresh()->quantidade_atual);
        $event['event_id'] = 'reject';
        $event['items'][0]['qtd'] = 20;
        $handler->handle($event);
        $this->assertSame(10, (int) $item->fresh()->quantidade_atual);
        $this->assertDatabaseHas('integration_outbox', ['event_type' => 'stock.adjustment.failed']);
    }

    public function test_timeout_reverses_applied_stock_once_and_replays_result(): void
    {
        $item = Estoque::create(['descricao' => 'Peça', 'tipo' => 'peca', 'quantidade_atual' => 10, 'valor_venda' => 10, 'ativo' => true]);
        $request = ['event_id' => 'request', 'event_type' => 'OSStockAdjustmentRequested', 'saga_id' => 'saga', 'operation_id' => 'operation', 'osid' => 1, 'action' => 'create', 'items' => [['id' => $item->estoqid, 'qtd' => 2]]];
        $handler = app(StockAdjustmentRequestedHandler::class);
        $handler->handle($request);
        $this->assertSame(8, (int) $item->fresh()->quantidade_atual);
        $cancel = array_merge($request, ['event_id' => 'cancel', 'event_type' => 'OSStockAdjustmentCancellationRequested', 'request_event_id' => 'request']);
        $handler->handle($cancel);
        $handler->handle($cancel);
        $handler->handle($request);
        $this->assertSame(10, (int) $item->fresh()->quantidade_atual);
        $this->assertDatabaseCount('stock_operations', 2);
        $this->assertDatabaseHas('stock_saga_guards', ['request_event_id' => 'request', 'state' => 'CANCELLED']);
        $this->assertDatabaseHas('integration_outbox', ['event_type' => 'stock.adjustment.failed', 'published_at' => null]);
    }

    public function test_cancellation_before_late_request_prevents_reservation(): void
    {
        $item = Estoque::create(['descricao' => 'Peça', 'tipo' => 'peca', 'quantidade_atual' => 10, 'valor_venda' => 10, 'ativo' => true]);
        $request = ['event_id' => 'request', 'event_type' => 'OSStockAdjustmentRequested', 'saga_id' => 'saga', 'operation_id' => 'operation', 'osid' => 1, 'action' => 'create', 'items' => [['id' => $item->estoqid, 'qtd' => 2]]];
        $handler = app(StockAdjustmentRequestedHandler::class);
        $handler->handle(array_merge($request, ['event_id' => 'cancel', 'event_type' => 'OSStockAdjustmentCancellationRequested', 'request_event_id' => 'request']));
        $handler->handle($request);
        $this->assertSame(10, (int) $item->fresh()->quantidade_atual);
        $this->assertDatabaseCount('stock_operations', 0);
        $this->assertDatabaseHas('integration_outbox', ['event_type' => 'stock.adjustment.failed']);
    }

    public function test_reused_key_with_different_payload_is_rejected(): void
    {
        $item = Estoque::create(['descricao' => 'Peça', 'tipo' => 'peca', 'quantidade_atual' => 10, 'valor_venda' => 10, 'ativo' => true]);
        $service = app(StockAdjustmentService::class);
        $first = $service->apply('key', [['id' => $item->estoqid, 'qtd' => 1]]);
        $this->assertSame($first, $service->apply('key', [['id' => $item->estoqid, 'qtd' => 1]]));
        $this->expectException(StockAdjustmentRejected::class);
        $service->apply('key', [['id' => $item->estoqid, 'qtd' => 2]]);
    }

    public function test_invalid_event_is_rejected_before_writing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(StockAdjustmentRequestedHandler::class)->handle([]);
    }
}
