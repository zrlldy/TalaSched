<?php

namespace App\Http\Controllers\Scheduling;

use App\Http\Controllers\Controller;
use App\Http\Requests\Scheduling\TimetableViewRequest;
use App\Models\AcademicUnit;
use App\Models\Organization;
use App\Models\ScheduleEntry;
use App\Models\SchedulingResource;
use App\Models\TimetableVersion;
use App\Scheduling\TimetableViewData;
use App\Scheduling\TimetableViewQuery;
use Inertia\Inertia;
use Inertia\Response;

class TimetableWorkspaceController extends Controller
{
    public function show(
        TimetableViewRequest $request,
        Organization $currentOrganization,
        TimetableViewQuery $query,
    ): Response {
        $timetable = $request->timetable();
        $filters = $request->filters();
        $view = $query->handle($currentOrganization, $timetable, $filters);
        $viewAttributes = $this->viewAttributes($view);

        return Inertia::render('scheduling/Workspace', [
            'view' => $viewAttributes,
            'timetableId' => $timetable->public_id,
            'filters' => [
                'scope' => $filters->scope,
                'version_id' => $filters->versionPublicId,
                'resource_id' => $filters->resourcePublicId,
                'unit_id' => $filters->unitPublicId,
                'date' => $filters->date?->toDateString(),
                'weekday' => $filters->weekday,
            ],
            'versions' => TimetableVersion::query()
                ->where('organization_id', $currentOrganization->getKey())
                ->where('timetable_id', $timetable->getKey())
                ->orderByDesc('version_number')
                ->get()
                ->map(fn (TimetableVersion $version): array => [
                    'id' => $version->public_id,
                    'number' => (int) $version->version_number,
                    'status' => $version->status->value,
                ])
                ->values()
                ->all(),
            'resources' => SchedulingResource::query()
                ->where('organization_id', $currentOrganization->getKey())
                ->where('is_active', true)
                ->orderBy('type')
                ->orderBy('name')
                ->get()
                ->map(fn (SchedulingResource $resource): array => [
                    'id' => $resource->public_id,
                    'name' => $resource->name,
                    'type' => $resource->type->value,
                ])
                ->values()
                ->all(),
            'units' => AcademicUnit::query()
                ->where('organization_id', $currentOrganization->getKey())
                ->orderBy('name')
                ->get()
                ->map(fn (AcademicUnit $unit): array => [
                    'id' => $unit->public_id,
                    'name' => $unit->name,
                    'code' => $unit->code,
                ])
                ->values()
                ->all(),
            'canManageScheduling' => $request->user()?->can('create', [ScheduleEntry::class, $currentOrganization]) === true,
        ]);
    }

    /** @return array<string, mixed> */
    private function viewAttributes(TimetableViewData $view): array
    {
        $attributes = $view->toArray()['attributes'];

        return $attributes;
    }
}
