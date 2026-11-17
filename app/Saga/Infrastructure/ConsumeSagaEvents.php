<?php

namespace App\Saga\Infrastructure;

use App\Support\DogStatsD;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Message\AMQPMessage;

final class ConsumeSagaEvents
{
    public function run(string $queue, callable $handler, RabbitMq $rabbit, bool $once = false): void
    {
        while (true) {
            $connection = null;
            $channel = null;
            try {
                $connection = $rabbit->connect();
                $channel = $connection->channel();
                $rabbit->declareTopology($channel);
                $channel->basic_qos(null, 1, null);
                $channel->basic_consume($queue, '', false, false, false, false,
                    function (AMQPMessage $message) use ($handler, $channel, $rabbit, $queue): void {
                        try {
                            $event = json_decode($message->getBody(), true, 512, JSON_THROW_ON_ERROR);
                            $handler($event);
                            $message->ack();
                        } catch (\InvalidArgumentException|\JsonException $exception) {
                            Log::critical('Evento inválido enviado para dead-letter', [
                                'queue' => $queue, 'message_id' => $message->get('message_id'),
                            ]);
                            $channel->basic_publish($message, RabbitMq::DEAD_EXCHANGE, 'invalid');
                            DogStatsD::metric('mws.saga.dead_letter', 1, 'c', ['service' => (string) env('DD_SERVICE', 'mws')]);
                            $message->ack();
                        } catch (\Throwable $exception) {
                            Log::error('Falha ao processar evento da Saga; será tentado novamente', [
                                'queue' => $queue, 'message_id' => $message->get('message_id'),
                                'correlation_id' => $message->get('correlation_id'),
                                'exception' => $exception::class,
                            ]);
                            $rabbit->retry($channel, $message);
                            $message->ack();
                        }
                    }
                );

                while ($channel->is_consuming()) {
                    $channel->wait();
                }
            } catch (\Throwable $exception) {
                Log::warning('Conexão do consumidor Saga indisponível; reconectando', [
                    'queue' => $queue, 'exception' => $exception::class,
                ]);
                if (! $once) {
                    sleep(2);
                }
            } finally {
                try {
                    $channel?->close();
                } catch (\Throwable) {
                }
                try {
                    $connection?->close();
                } catch (\Throwable) {
                }
            }
            if ($once) {
                return;
            }
        }
    }
}
