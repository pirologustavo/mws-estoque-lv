<?php

namespace App\Console\Commands;

use App\Saga\Infrastructure\RabbitMq;
use App\Support\DogStatsD;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PublishSagaOutbox extends Command
{
    protected $signature = 'saga:publish-outbox {--once : Publish one batch and exit}';

    protected $description = 'Publish committed Saga events from the transactional outbox';

    public function handle(RabbitMq $rabbit): int
    {
        do {
            try {
                $connection = $rabbit->connect();
                $channel = $connection->channel();
                $rabbit->declareTopology($channel);
                $channel->confirm_select();

                $events = DB::table('integration_outbox')
                    ->whereNull('published_at')->where('available_at', '<=', now())
                    ->orderBy('created_at')->limit(100)->get();

                foreach ($events as $event) {
                    try {
                        $payload = json_decode($event->payload, true, 512, JSON_THROW_ON_ERROR);
                        $rabbit->publish($channel, $event->routing_key, $payload, $event->id, $event->correlation_id);
                        $channel->wait_for_pending_acks_returns(5.0);
                        DB::table('integration_outbox')->where('id', $event->id)->whereNull('published_at')->update([
                            'published_at' => now(), 'last_error' => null, 'updated_at' => now(),
                        ]);
                    } catch (\Throwable $exception) {
                        $attempts = (int) $event->attempts + 1;
                        $delay = min(300, 2 ** min($attempts, 8));
                        DB::table('integration_outbox')->where('id', $event->id)->update([
                            'attempts' => $attempts,
                            'available_at' => now()->addSeconds($delay),
                            'last_error' => mb_substr($exception->getMessage(), 0, 4000),
                            'updated_at' => now(),
                        ]);
                        Log::error('Falha ao publicar evento da Saga', [
                            'event_id' => $event->id, 'event_type' => $event->event_type,
                            'correlation_id' => $event->correlation_id, 'attempts' => $attempts,
                        ]);
                        DogStatsD::metric('mws.saga.outbox.failures', 1, 'c', ['service' => 'estoque-lv']);
                    }
                }

                $channel->close();
                $connection->close();
            } catch (\Throwable $exception) {
                Log::warning('RabbitMQ indisponível para publicação da outbox', ['exception' => $exception::class]);
                if ($this->option('once')) {
                    return self::FAILURE;
                }
                sleep(2);
            }

            if (! $this->option('once')) {
                sleep(1);
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }
}
