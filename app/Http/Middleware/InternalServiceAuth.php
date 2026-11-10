<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InternalServiceAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.internal_token');
        $provided = (string) $request->header('X-Internal-Token');
        abort_if($expected === '' || !hash_equals($expected, $provided), 401, 'Credencial de serviço inválida.');
        return $next($request);
    }
}
