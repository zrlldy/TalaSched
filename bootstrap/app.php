<?php

use App\Http\Middleware\AssignCorrelationId;
use App\Http\Middleware\EnsureIdempotentRequest;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetOrganizationUrlDefaults;
use App\Http\Responses\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            AssignCorrelationId::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            SetOrganizationUrlDefaults::class,
        ]);

        $middleware->alias([
            'idempotent' => EnsureIdempotentRequest::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (ValidationException $exception, Request $request): ?JsonResponse {
            if (! $request->routeIs('scheduling.*') || $request->routeIs('scheduling.timetables.index', 'scheduling.timetables.store') && ! $request->expectsJson()) {
                return null;
            }

            $issues = collect($exception->errors())
                ->map(fn (array $messages, string $field): array => [
                    'code' => 'validation_failed',
                    'severity' => 'hard',
                    'field' => $field,
                    'message' => $messages[0],
                    'details' => ['messages' => $messages],
                ])
                ->values()
                ->all();

            return ApiResponse::error(
                request: $request,
                code: 'validation_failed',
                message: 'The request data is invalid.',
                status: 422,
                issues: $issues,
            );
        });

        $exceptions->render(function (AuthenticationException $exception, Request $request): ?JsonResponse {
            if (! $request->routeIs('scheduling.*') || $request->routeIs('scheduling.timetables.index', 'scheduling.timetables.store') && ! $request->expectsJson()) {
                return null;
            }

            return ApiResponse::error($request, 'unauthenticated', 'Authentication is required.', 401);
        });

        $exceptions->render(function (HttpExceptionInterface $exception, Request $request): ?JsonResponse {
            if (! $request->routeIs('scheduling.*') || $request->routeIs('scheduling.timetables.index', 'scheduling.timetables.store') && ! $request->expectsJson()) {
                return null;
            }

            [$code, $message] = match ($exception->getStatusCode()) {
                403 => ['forbidden', 'You are not authorized to perform this action.'],
                404 => ['not_found', 'The requested resource was not found.'],
                429 => ['rate_limited', 'Too many requests. Retry later.'],
                default => ['invalid_request', 'The request could not be completed.'],
            };

            return ApiResponse::error($request, $code, $message, $exception->getStatusCode());
        });
    })->create();
