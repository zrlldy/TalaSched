<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AssignCorrelationId
{
    public const string Attribute = 'correlation_id';

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $requestedCorrelationId = $request->header('X-Correlation-ID');
        $correlationId = is_string($requestedCorrelationId) && Str::isUuid($requestedCorrelationId)
            ? $requestedCorrelationId
            : (string) Str::uuid();

        $request->attributes->set(self::Attribute, $correlationId);
        Context::add(self::Attribute, $correlationId);

        try {
            $response = $next($request);
            $response->headers->set('X-Correlation-ID', $correlationId);

            return $response;
        } finally {
            Context::forget(self::Attribute);
        }
    }
}
