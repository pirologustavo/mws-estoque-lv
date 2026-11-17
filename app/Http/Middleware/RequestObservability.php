<?php

namespace App\Http\Middleware;

use App\Support\DogStatsD;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RequestObservability
{
    public function handle(Request $request, Closure $next): Response
    {
        $correlationId = (string) $request->header('X-Correlation-ID', '');
        if ($correlationId === '' || ! preg_match('/^[A-Za-z0-9._-]{1,100}$/', $correlationId)) {
            $correlationId = (string) Str::uuid();
        }
        $request->attributes->set('correlation_id', $correlationId);
        Log::withContext(['correlation_id' => $correlationId, 'service' => (string) env('DD_SERVICE', 'app')]);
        $startedAt = hrtime(true);
        $status = 500;

        try {
            $response = $next($request);
            $status = $response->getStatusCode();
            $response->headers->set('X-Correlation-ID', $correlationId);

            return $response;
        } finally {
            $durationMs = (hrtime(true) - $startedAt) / 1_000_000;
            $route = $request->route()?->uri() ?? 'unmatched';
            Log::log($status >= 500 ? 'error' : 'info', 'http.request.completed', [
                'http_method' => $request->method(), 'http_route' => $route,
                'http_status_code' => $status, 'duration_ms' => round($durationMs, 2),
            ]);
            DogStatsD::metric('mws.api.request.duration', $durationMs, 'ms', [
                'service' => (string) env('DD_SERVICE', 'app'),
                'route' => str_replace([' ', ',', '|'], '_', $route), 'status_code' => $status,
            ]);
            if ($status >= 500) {
                DogStatsD::metric('mws.api.errors', 1, 'c', ['service' => (string) env('DD_SERVICE', 'app')]);
                if ((string) env('DD_SERVICE', '') === 'os-lv') {
                    DogStatsD::metric('mws.os.processing.failures', 1, 'c', ['service' => 'os-lv']);
                }
            }
        }
    }
}
