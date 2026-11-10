<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class SharedServiceTokenAuth
{
    public function handle(Request $request, Closure $next)
    {
        $bearer = $request->bearerToken();
        if (!$bearer) throw new UnauthorizedHttpException('Bearer', 'Token ausente.');

        $baseUrl = (string) config('services.internal.funcionario');
        if ($baseUrl === '') throw new HttpException(503, 'Serviço de autenticação não configurado.');

        $correlationId = $request->attributes->get('correlation_id')
            ?? $request->header('X-Correlation-ID')
            ?? (string) \Illuminate\Support\Str::uuid();
        $request->attributes->set('correlation_id', $correlationId);

        try {
            $response = Http::acceptJson()->withHeaders([
                'Authorization' => 'Bearer ' . $bearer,
                'X-Internal-Token' => (string) config('services.internal_token'),
                'X-Correlation-ID' => $correlationId,
            ])->connectTimeout(2)->timeout(5)->get(rtrim($baseUrl, '/') . '/api/internal/v1/token');
            if (!$response->successful()) throw new HttpException(503, 'Serviço de autenticação indisponível.');
            if (!$response->json('valid', false)) throw new UnauthorizedHttpException('Bearer', 'Token inválido.');
        } catch (HttpException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new HttpException(503, 'Serviço de autenticação indisponível.');
        }

        return $next($request);
    }
}
