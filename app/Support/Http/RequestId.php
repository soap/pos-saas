<?php

namespace App\Support\Http;

use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * กำหนด request_id สำหรับ trace (spec §7.2) และ reset tenant context ทุก request
 */
class RequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        app(TenantContext::class)->reset();

        $incoming = (string) $request->headers->get('X-Request-Id', '');
        $id = preg_match('/^[A-Za-z0-9\-_.]{8,64}$/', $incoming) ? $incoming : (string) Str::uuid();
        $request->attributes->set('request_id', $id);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
