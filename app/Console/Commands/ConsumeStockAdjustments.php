<?php

namespace App\Console\Commands;

use App\Saga\Infrastructure\ConsumeSagaEvents;
use App\Saga\Infrastructure\RabbitMq;
use App\Saga\StockAdjustmentRequestedHandler;
use Illuminate\Console\Command;

class ConsumeStockAdjustments extends Command
{
    protected $signature = 'saga:consume-stock-adjustments';

    protected $description = 'Consume OS stock adjustment events idempotently';

    public function handle(ConsumeSagaEvents $consumer, RabbitMq $rabbit): int
    {
        $consumer->run('estoque.stock-adjustments', fn (array $event) => app(StockAdjustmentRequestedHandler::class)->handle($event), $rabbit);

        return self::SUCCESS;
    }
}
