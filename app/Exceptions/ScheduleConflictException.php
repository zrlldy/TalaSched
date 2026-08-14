<?php

namespace App\Exceptions;

use App\Http\Responses\ApiResponse;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class ScheduleConflictException extends RuntimeException implements ShouldntReport
{
    /**
     * @param  array<int, array<string, mixed>>  $issues
     */
    public function __construct(public readonly array $issues)
    {
        parent::__construct('The schedule entry conflicts with existing scheduling rules.');
    }

    public function render(Request $request): JsonResponse
    {
        return ApiResponse::error(
            request: $request,
            code: 'schedule_conflict',
            message: $this->getMessage(),
            status: 422,
            issues: $this->issues,
        );
    }
}
