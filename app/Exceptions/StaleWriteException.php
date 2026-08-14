<?php

namespace App\Exceptions;

use App\Http\Responses\ApiResponse;
use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaleWriteException extends Exception implements ShouldntReport
{
    /** @param array<string, mixed> $current */
    public function __construct(public readonly array $current)
    {
        parent::__construct('This record changed after it was loaded. Review the current version before retrying.');
    }

    public function render(Request $request): JsonResponse
    {
        return ApiResponse::error(
            request: $request,
            code: 'stale_write',
            message: $this->getMessage(),
            status: 409,
            additionalErrorData: ['current' => $this->current],
        );
    }
}
