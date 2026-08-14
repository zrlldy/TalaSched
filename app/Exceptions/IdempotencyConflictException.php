<?php

namespace App\Exceptions;

use App\Http\Responses\ApiResponse;
use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IdempotencyConflictException extends Exception implements ShouldntReport
{
    public function __construct()
    {
        parent::__construct('The idempotency key has already been used with different request data.');
    }

    public function render(Request $request): JsonResponse
    {
        return ApiResponse::error(
            request: $request,
            code: 'idempotency_conflict',
            message: $this->getMessage(),
            status: 409,
        );
    }
}
