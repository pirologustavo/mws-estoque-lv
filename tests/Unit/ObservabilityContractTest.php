<?php

namespace Tests\Unit;

use App\Http\Middleware\RequestObservability;
use App\Support\DogStatsD;
use Illuminate\Http\Request;
use Illuminate\Support\Env;
use Illuminate\Support\Str;
use Tests\TestCase;

class ObservabilityContractTest extends TestCase
{
    public function test_metric_is_sent_as_dogstatsd_with_tags(): void
    {
        $socket = stream_socket_server('udp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND);
        $this->assertNotFalse($socket);
        $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        $env = Env::getRepository();
        $old = $env->get('DD_AGENT_HOST');
        $env->set('DD_AGENT_HOST', '127.0.0.1');
        $env->set('DD_DOGSTATSD_PORT', (string) $port);
        try {
            DogStatsD::metric('test.count', 2, 'c', ['service' => 'test']);
            stream_set_timeout($socket, 1);
            $this->assertStringContainsString('test.count:2|c|#service:test', stream_socket_recvfrom($socket, 4096));
        } finally {
            fclose($socket);
            $env->set('DD_AGENT_HOST', $old ?? '');
            $env->clear('DD_DOGSTATSD_PORT');
        }
    }

    public function test_correlation_and_error_metrics_are_recorded(): void
    {
        $request = Request::create('/failure');
        $request->headers->set('X-Correlation-ID', 'invalid space');
        $response = (new RequestObservability)->handle($request, fn () => response('failed', 500));
        $this->assertSame(500, $response->getStatusCode());
        $this->assertTrue(Str::isUuid($response->headers->get('X-Correlation-ID')));
    }
}
