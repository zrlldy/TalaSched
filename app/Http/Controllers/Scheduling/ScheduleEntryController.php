<?php

namespace App\Http\Controllers\Scheduling;

use App\Http\Controllers\Controller;
use App\Http\Requests\Scheduling\DeleteScheduleEntryRequest;
use App\Http\Requests\Scheduling\StoreScheduleEntryRequest;
use App\Http\Requests\Scheduling\UpdateScheduleEntryRequest;
use App\Http\Resources\Scheduling\ScheduleEntryResource;
use App\Http\Responses\ApiResponse;
use App\Models\Organization;
use App\Scheduling\CreateScheduleEntry;
use App\Scheduling\DeleteScheduleEntry;
use App\Scheduling\UpdateScheduleEntry;
use App\Scheduling\ValidateScheduleEntry;
use Illuminate\Http\JsonResponse;

class ScheduleEntryController extends Controller
{
    public function validateEntry(
        StoreScheduleEntryRequest $request,
        Organization $currentOrganization,
        ValidateScheduleEntry $validator,
    ): JsonResponse {
        $result = $validator->evaluate($currentOrganization, $request->scheduleEntryData());

        return ApiResponse::success($request, [
            'type' => 'schedule_validation',
            'attributes' => [
                'valid' => ! $result->hasHardIssues(),
                'issues' => $result->toArray(),
                'hard_issues' => $result->hardIssues()->toArray(),
                'warnings' => $result->warnings()->toArray(),
                'score' => $result->score,
            ],
        ]);
    }

    public function store(
        StoreScheduleEntryRequest $request,
        Organization $currentOrganization,
        CreateScheduleEntry $action,
    ): JsonResponse {
        $entry = $action->handle(
            $currentOrganization,
            $request->scheduleEntryData(),
            actor: $request->user(),
        );

        return ApiResponse::success(
            request: $request,
            data: (new ScheduleEntryResource($entry))->resolve($request),
            status: 201,
        );
    }

    public function update(
        UpdateScheduleEntryRequest $request,
        Organization $currentOrganization,
        UpdateScheduleEntry $action,
    ): JsonResponse {
        $entry = $request->entry();
        $updatedEntry = $action->handle(
            $currentOrganization,
            $entry,
            $request->scheduleEntryData($entry),
            (int) $request->validated('lock_version'),
            actor: $request->user(),
        );

        return ApiResponse::success(
            request: $request,
            data: (new ScheduleEntryResource($updatedEntry))->resolve($request),
        );
    }

    public function destroy(
        DeleteScheduleEntryRequest $request,
        Organization $currentOrganization,
        DeleteScheduleEntry $action,
    ): JsonResponse {
        $entry = $request->entry();
        $action->handle(
            $currentOrganization,
            $entry,
            (int) $request->validated('lock_version'),
            actor: $request->user(),
        );

        return ApiResponse::success($request, [
            'type' => 'schedule_entry_deleted',
            'attributes' => ['id' => $entry->public_id],
        ]);
    }
}
