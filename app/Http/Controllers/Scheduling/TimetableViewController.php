<?php

namespace App\Http\Controllers\Scheduling;

use App\Http\Controllers\Controller;
use App\Http\Requests\Scheduling\TimetableViewRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Organization;
use App\Scheduling\TimetableViewQuery;
use Illuminate\Http\JsonResponse;

class TimetableViewController extends Controller
{
    public function show(
        TimetableViewRequest $request,
        Organization $currentOrganization,
        TimetableViewQuery $query,
    ): JsonResponse {
        $view = $query->handle(
            $currentOrganization,
            $request->timetable(),
            $request->filters(),
        );

        return ApiResponse::success($request, $view->toArray());
    }
}
