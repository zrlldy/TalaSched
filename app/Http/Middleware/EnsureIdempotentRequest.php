<?php

namespace App\Http\Middleware;

use App\Exceptions\IdempotencyConflictException;
use App\Http\Responses\ApiResponse;
use App\Models\IdempotencyRecord;
use App\Models\Organization;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class EnsureIdempotentRequest
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $idempotencyKey = trim((string) $request->header('Idempotency-Key', ''));

        if ($idempotencyKey === '' || Str::length($idempotencyKey) > 255) {
            return ApiResponse::error(
                request: $request,
                code: 'validation_failed',
                message: 'A valid Idempotency-Key header is required for this command.',
                status: 422,
                issues: [[
                    'code' => 'validation_failed',
                    'severity' => 'hard',
                    'field' => 'idempotency_key',
                    'message' => 'The Idempotency-Key header must contain between 1 and 255 characters.',
                    'details' => [],
                ]],
            );
        }

        $organization = $request->route('current_organization');
        $actor = $request->user();

        abort_unless($organization instanceof Organization && $actor !== null, 403);

        $routeName = (string) $request->route()?->getName();
        $keyHash = hash('sha256', $idempotencyKey);
        $requestHash = $this->requestHash($request);
        $lockName = implode(':', ['idempotency', $organization->id, $actor->id, $routeName, $keyHash]);

        try {
            return Cache::lock($lockName, 30)->block(5, function () use (
                $request,
                $next,
                $organization,
                $actor,
                $routeName,
                $keyHash,
                $requestHash,
            ): Response {
                $record = IdempotencyRecord::query()
                    ->where('organization_id', $organization->id)
                    ->where('actor_user_id', $actor->id)
                    ->where('route', $routeName)
                    ->where('key_hash', $keyHash)
                    ->first();

                if ($record?->expires_at?->isPast()) {
                    $record->delete();
                    $record = null;
                }

                if ($record !== null && ! hash_equals($record->request_hash, $requestHash)) {
                    throw new IdempotencyConflictException;
                }

                if ($record?->status === IdempotencyRecord::Completed) {
                    return $this->replayedResponse($request, $record);
                }

                if ($record !== null) {
                    return ApiResponse::error(
                        request: $request,
                        code: 'service_unavailable',
                        message: 'A request with this idempotency key is still being processed.',
                        status: 503,
                    )->header('Retry-After', '5');
                }

                $record = IdempotencyRecord::create([
                    'organization_id' => $organization->id,
                    'actor_user_id' => $actor->id,
                    'route' => $routeName,
                    'key_hash' => $keyHash,
                    'request_hash' => $requestHash,
                    'status' => IdempotencyRecord::Processing,
                    'expires_at' => now()->addMinutes(5),
                ]);

                try {
                    $response = $next($request);
                } catch (Throwable $exception) {
                    $record->delete();

                    throw $exception;
                }

                if (! $response->isSuccessful() || ! $response instanceof JsonResponse) {
                    $record->delete();

                    return $response;
                }

                $content = $response->getContent();
                $responseBody = is_string($content)
                    ? json_decode($content, true, flags: JSON_THROW_ON_ERROR)
                    : [];

                $record->update([
                    'status' => IdempotencyRecord::Completed,
                    'response_status' => $response->getStatusCode(),
                    'response_body' => $responseBody,
                    'expires_at' => now()->addDay(),
                ]);

                return $response;
            });
        } catch (LockTimeoutException) {
            return ApiResponse::error(
                request: $request,
                code: 'service_unavailable',
                message: 'This command is already being processed. Retry shortly.',
                status: 503,
            )->header('Retry-After', '5');
        }
    }

    private function requestHash(Request $request): string
    {
        $payload = $this->canonicalize($request->all());

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }

    private function replayedResponse(Request $request, IdempotencyRecord $record): JsonResponse
    {
        /** @var array<string, mixed> $responseBody */
        $responseBody = $record->response_body;

        return ApiResponse::json($request, $responseBody, (int) $record->response_status)
            ->header('Idempotency-Replayed', 'true');
    }
}
