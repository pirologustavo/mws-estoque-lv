<?php

namespace Tests\Unit;

use App\Http\Middleware\InternalServiceAuth;
use Illuminate\Http\Request;
use Tests\TestCase;

class InternalServiceAuthTest extends TestCase
{
    public function test_accepts_a_request_with_the_configured_internal_token(): void
    {
        config(['services.internal_token' => 'service-secret']);
        $request = Request::create('/api/internal/v1/stock-adjustments');
        $request->headers->set('X-Internal-Token', 'service-secret');

        $response = (new InternalServiceAuth())->handle($request, fn () => response('accepted'));

        $this->assertSame('accepted', $response->getContent());
    }

    public function test_rejects_a_request_with_an_invalid_internal_token(): void
    {
        config(['services.internal_token' => 'service-secret']);
        $request = Request::create('/api/internal/v1/stock-adjustments');
        $request->headers->set('X-Internal-Token', 'wrong-secret');

        try {
            (new InternalServiceAuth())->handle($request, fn () => response('accepted'));
            $this->fail('Expected an unauthorized exception.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(401, $exception->getStatusCode());
        }
    }
}
