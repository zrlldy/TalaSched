<?php

namespace App\Http\Controllers\Scheduling;

use App\Http\Controllers\Controller;
use App\Http\Requests\Scheduling\StoreScheduleEntryRequest;
use App\Http\Resources\Scheduling\ScheduleEntryResource;
use App\Http\Responses\ApiResponse;
use App\Models\Organization;
use App\Scheduling\CreateScheduleEntry;
use App\Scheduling\ValidateScheduleEntry;
use Illuminate\Http\JsonResponse;

class ScheduleEntryController extends Controller
{
    public function validateEntry(
        StoreScheduleEntryRequest $request,
        Organization $currentOrganization,
        ValidateScheduleEntry $validator,
    ): JsonResponse {
        $issues = $validator->handle($currentOrganization, $request->scheduleEntryData());

        return ApiResponse::success($request, [
            'type' => 'schedule_validation',
            'attributes' => [
                'valid' => $issues === [],
                'issues' => $issues,
            ],
        ]);
    }

    public function store(
        StoreScheduleEntryRequest $request,
        Organization $currentOrganization,
        CreateScheduleEntry $action,
    ): JsonResponse {
        $entry = $action->handle($currentOrganization, $request->scheduleEntryData());

        return ApiResponse::success(
            request: $request,
            data: (new ScheduleEntryResource($entry))->resolve($request),
            status: 201,
        );
    }
}
