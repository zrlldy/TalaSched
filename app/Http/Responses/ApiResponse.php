<?php

namespace App\Http\Responses;

use App\Http\Middleware\AssignCorrelationId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

final class ApiResponse
{
    /** @param array<string, mixed> $data */
    public static function success(Request $request, array $data, int $status = 200): JsonResponse
    {
        return self::json($request, [
            'data' => $data,
            'meta' => self::meta($request),
        ], $status);
    }

    /**
     * @param  array<int, array<string, mixed>>  $issues
     * @param  array<string, mixed>  $additionalErrorData
     */
    public static function error(
        Request $request,
        string $code,
        string $message,
        int $status,
        array $issues = [],
        array $additionalErrorData = [],
    ): JsonResponse {
        return self::json($request, [
            'error' => [
                'code' => $code,
                'message' => $message,
                'issues' => $issues,
                ...$additionalErrorData,
            ],
            'meta' => self::meta($request),
        ], $status);
    }

    /** @param array<string, mixed> $payload */
    public static function json(Request $request, array $payload, int $status): JsonResponse
    {
        $payload['meta']['correlation_id'] = self::correlationId($request);

        return response()
            ->json($payload, $status)
            ->header('X-Correlation-ID', self::correlationId($request));
    }

    /** @return array{correlation_id: string} */
    public static function meta(Request $request): array
    {
        return ['correlation_id' => self::correlationId($request)];
    }

    public static function correlationId(Request $request): string
    {
        $correlationId = $request->attributes->get(AssignCorrelationId::Attribute);

        if (! is_string($correlationId) || ! Str::isUuid($correlationId)) {
            $correlationId = (string) Str::uuid();
            $request->attributes->set(AssignCorrelationId::Attribute, $correlationId);
            Context::add(AssignCorrelationId::Attribute, $correlationId);
        }

        return $correlationId;
    }
}
