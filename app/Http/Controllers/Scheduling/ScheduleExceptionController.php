<?php

namespace App\Http\Controllers\Scheduling;

use App\Http\Controllers\Controller;
use App\Http\Requests\Scheduling\StoreScheduleExceptionRequest;
use App\Http\Resources\Scheduling\ScheduleEntryExceptionResource;
use App\Http\Responses\ApiResponse;
use App\Models\Organization;
use App\Scheduling\ApplyScheduleException;
use Illuminate\Http\JsonResponse;

class ScheduleExceptionController extends Controller
{
    public function store(
        StoreScheduleExceptionRequest $request,
        Organization $currentOrganization,
        ApplyScheduleException $action,
    ): JsonResponse {
        $exception = $action->handle(
            $currentOrganization,
            $request->entry(),
            $request->scheduleExceptionData(),
            actor: $request->user(),
        );

        return ApiResponse::success(
            request: $request,
            data: (new ScheduleEntryExceptionResource($exception))->resolve($request),
            status: 201,
        );
    }
}
