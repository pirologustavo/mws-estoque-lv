<?php

namespace Tests\Feature;

use App\Saga\Infrastructure\ConsumeSagaEvents;
use App\Saga\Infrastructure\Outbox;
use App\Saga\Infrastructure\RabbitMq;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Tests\TestCase;

class SagaTransportTest extends TestCase
{
    use RefreshDatabase;

    public function test_topology_persistent_publish_retry_and_dead_letter(): void
    {
        $channel = Mockery::mock(AMQPChannel::class)->shouldIgnoreMissing();
        $rabbit = new RabbitMq;
        $rabbit->declareTopology($channel);
        $rabbit->publish($channel, 'test', ['value' => 1], 'event', 'corr');
        foreach (['os.stock.adjustment.requested', 'stock.adjustment.succeeded', 'stock.adjustment.failed'] as $routing) {
            $message = new AMQPMessage('{}', ['application_headers' => new AMQPTable(['x-retry-count' => 0])]);
            $message->setDeliveryInfo(1, false, 'mws.saga', $routing);
            $rabbit->retry($channel, $message);
        }
        $message = new AMQPMessage('{}', ['application_headers' => new AMQPTable(['x-retry-count' => 5])]);
        $rabbit->retry($channel, $message);
        $channel->shouldHaveReceived('basic_publish')->times(5);
        $channel->shouldHaveReceived('exchange_declare');
    }

    public function test_publisher_commits_confirmation_and_retries_failed_publish(): void
    {
        foreach ([false, true] as $fails) {
            $id = app(Outbox::class)->record('Test', 'test', 'OS', 1, ['osid' => 1], 'corr');
            $channel = Mockery::mock(AMQPChannel::class)->shouldIgnoreMissing();
            $connection = Mockery::mock(AMQPStreamConnection::class)->shouldIgnoreMissing();
            $connection->shouldReceive('channel')->andReturn($channel);
            $rabbit = Mockery::mock(RabbitMq::class)->shouldIgnoreMissing();
            $rabbit->shouldReceive('connect')->andReturn($connection);
            if ($fails) {
                $rabbit->shouldReceive('publish')->andThrow(new \RuntimeException('offline'));
            }
            $this->app->instance(RabbitMq::class, $rabbit);
            $this->artisan('saga:publish-outbox', ['--once' => true])->assertExitCode(0);
            $row = DB::table('integration_outbox')->where('id', $id)->first();
            if ($fails) {
                $this->assertNull($row->published_at);
                $this->assertSame(1, (int) $row->attempts);
            } else {
                $this->assertNotNull($row->published_at);
            }
        }
        $rabbit->shouldReceive('connect')->andThrow(new \RuntimeException('offline'));
    }

    public function test_consumer_acks_valid_invalid_and_retryable_messages(): void
    {
        foreach (['valid', 'invalid', 'transient'] as $mode) {
            $message = Mockery::mock(AMQPMessage::class)->shouldIgnoreMissing();
            $message->shouldReceive('getBody')->andReturn('{}');
            $message->shouldReceive('ack')->once();
            $channel = Mockery::mock(AMQPChannel::class)->shouldIgnoreMissing();
            $channel->shouldReceive('basic_consume')->andReturnUsing(function (...$args) use ($message) {
                ($args[6])($message);
            });
            $channel->shouldReceive('is_consuming')->andReturn(false);
            $connection = Mockery::mock(AMQPStreamConnection::class)->shouldIgnoreMissing();
            $connection->shouldReceive('channel')->andReturn($channel);
            $rabbit = Mockery::mock(RabbitMq::class)->shouldIgnoreMissing();
            $rabbit->shouldReceive('connect')->andReturn($connection);
            (new ConsumeSagaEvents)->run('test', function () use ($mode) {
                if ($mode === 'invalid') {
                    throw new \InvalidArgumentException('invalid');
                } if ($mode === 'transient') {
                    throw new \RuntimeException('offline');
                }
            }, $rabbit, true);
            if ($mode === 'invalid') {
                $channel->shouldHaveReceived('basic_publish');
            }
            if ($mode === 'transient') {
                $rabbit->shouldHaveReceived('retry');
            }
        }
        $rabbit = Mockery::mock(RabbitMq::class);
        $rabbit->shouldReceive('connect')->andThrow(new \RuntimeException('offline'));
        (new ConsumeSagaEvents)->run('test', fn () => null, $rabbit, true);
        $this->assertTrue(true);
    }
}
