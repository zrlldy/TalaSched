<?php

namespace App\Http\Controllers\Scheduling;

use App\Http\Controllers\Controller;
use App\Http\Requests\Scheduling\CompareTimetableVersionsRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Organization;
use App\Scheduling\CompareTimetableVersions;
use Illuminate\Http\JsonResponse;

class TimetableVersionComparisonController extends Controller
{
    public function show(
        CompareTimetableVersionsRequest $request,
        Organization $currentOrganization,
        CompareTimetableVersions $comparison,
    ): JsonResponse {
        $result = $comparison->handle(
            $currentOrganization,
            $request->timetable(),
            $request->fromVersion(),
            $request->toVersion(),
        );

        return ApiResponse::success($request, $result->toArray());
    }
}
