<?php

namespace App\Saga\Infrastructure;

use App\Support\DogStatsD;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

class RabbitMq
{
    public const EXCHANGE = 'mws.saga';

    public const DEAD_EXCHANGE = 'mws.saga.dead';

    public function connect(): AMQPStreamConnection
    {
        $config = config('saga.rabbitmq');

        return new AMQPStreamConnection(
            $config['host'], $config['port'], $config['user'], $config['password'],
            $config['vhost'], false, 'AMQPLAIN', null, 'en_US', 5.0, 10.0,
            null, true, 60
        );
    }

    public function declareTopology($channel): void
    {
        $channel->exchange_declare(self::EXCHANGE, 'topic', false, true, false);
        $channel->exchange_declare(self::DEAD_EXCHANGE, 'topic', false, true, false);
        $channel->queue_declare('mws.saga.dead', false, true, false, false);
        $channel->queue_bind('mws.saga.dead', self::DEAD_EXCHANGE, '#');
        $this->declareConsumerQueue($channel, 'estoque.stock-adjustments', 'os.stock.adjustment.requested');
        $this->declareConsumerQueue($channel, 'os.stock-results', 'stock.adjustment.*');
        $this->declareRetryQueue($channel, 'estoque.stock-adjustments.retry', 'os.stock.adjustment.requested');
        $this->declareRetryQueue($channel, 'os.stock-results.succeeded.retry', 'stock.adjustment.succeeded');
        $this->declareRetryQueue($channel, 'os.stock-results.failed.retry', 'stock.adjustment.failed');
    }

    public function declareConsumerQueue($channel, string $queue, string $binding): void
    {
        $arguments = new AMQPTable(['x-dead-letter-exchange' => self::DEAD_EXCHANGE]);
        $channel->queue_declare($queue, false, true, false, false, false, $arguments);
        $channel->queue_bind($queue, self::EXCHANGE, $binding);
    }

    private function declareRetryQueue($channel, string $queue, string $routingKey): void
    {
        $retryArguments = new AMQPTable([
            'x-message-ttl' => 5000,
            'x-dead-letter-exchange' => self::EXCHANGE,
            'x-dead-letter-routing-key' => $routingKey,
        ]);
        $channel->queue_declare($queue, false, true, false, false, false, $retryArguments);
    }

    public function publish($channel, string $routingKey, array $payload, string $eventId, ?string $correlationId): void
    {
        $channel->basic_publish(new AMQPMessage(
            json_encode($payload, JSON_THROW_ON_ERROR),
            [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'message_id' => $eventId,
                'correlation_id' => $correlationId ?? '',
                'application_headers' => new AMQPTable(['x-retry-count' => 0]),
            ]
        ), self::EXCHANGE, $routingKey);
    }

    public function retry($channel, AMQPMessage $message): void
    {
        $headers = $message->get('application_headers');
        $headerData = $headers instanceof AMQPTable ? $headers->getNativeData() : [];
        $retryCount = (int) ($headerData['x-retry-count'] ?? 0);

        if ($retryCount >= 5) {
            $channel->basic_publish($this->copyMessage($message, $retryCount), self::DEAD_EXCHANGE, 'exhausted');
            DogStatsD::metric('mws.saga.dead_letter', 1, 'c', ['service' => 'os-lv']);

            return;
        }

        $queue = match ($message->getRoutingKey()) {
            'os.stock.adjustment.requested' => 'estoque.stock-adjustments.retry',
            'stock.adjustment.succeeded' => 'os.stock-results.succeeded.retry',
            default => 'os.stock-results.failed.retry',
        };
        $channel->basic_publish($this->copyMessage($message, $retryCount + 1), '', $queue);
    }

    private function copyMessage(AMQPMessage $message, int $retryCount): AMQPMessage
    {
        $properties = $message->get_properties();
        $properties['application_headers'] = new AMQPTable(['x-retry-count' => $retryCount]);
        $properties['delivery_mode'] = AMQPMessage::DELIVERY_MODE_PERSISTENT;

        return new AMQPMessage($message->getBody(), $properties);
    }
}
