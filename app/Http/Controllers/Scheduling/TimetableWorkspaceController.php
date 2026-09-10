<?php

namespace App\Http\Controllers\Scheduling;

use App\Enums\SubjectOfferingStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Scheduling\TimetableViewRequest;
use App\Models\AcademicUnit;
use App\Models\FacultyProfile;
use App\Models\OfferingComponent;
use App\Models\Organization;
use App\Models\ScheduleEntry;
use App\Models\SchedulingResource;
use App\Models\SubjectOffering;
use App\Models\TimetableVersion;
use App\Scheduling\TimetableViewData;
use App\Scheduling\TimetableViewQuery;
use Illuminate\Support\Facades\DB;
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
        $canManageScheduling = $request->user()?->can('create', [ScheduleEntry::class, $currentOrganization]) === true;

        $versions = TimetableVersion::query()
            ->withCount('entries')
            ->where('organization_id', $currentOrganization->getKey())
            ->where('timetable_id', $timetable->getKey())
            ->orderByDesc('version_number')
            ->get();
        $versionForPolicy = $versions->first();
        $canManageVersions = $versionForPolicy instanceof TimetableVersion
            && $request->user()?->can('clone', $versionForPolicy) === true;
        $canSubmitVersions = $versionForPolicy instanceof TimetableVersion
            && $request->user()?->can('submitForApproval', $versionForPolicy) === true;
        $versionWorkflows = [];

        if ($canSubmitVersions) {
            $workflowRows = DB::table('approval_workflows')
                ->join('approval_workflow_versions', 'approval_workflow_versions.approval_workflow_id', '=', 'approval_workflows.id')
                ->where('approval_workflows.organization_id', $currentOrganization->getKey())
                ->where('approval_workflows.is_active', true)
                ->whereNotNull('approval_workflow_versions.activated_at')
                ->orderBy('approval_workflows.name')
                ->get([
                    'approval_workflows.public_id as id',
                    'approval_workflows.name',
                    'approval_workflow_versions.version_number',
                ])
                ->groupBy('id');

            foreach ($workflowRows as $workflows) {
                $workflow = $workflows->sortByDesc('version_number')->first();

                if ($workflow === null) {
                    continue;
                }

                $versionWorkflows[] = [
                    'id' => (string) $workflow->id,
                    'name' => (string) $workflow->name,
                    'version' => (int) $workflow->version_number,
                ];
            }
        }

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
            'versions' => $versions->map(fn (TimetableVersion $version): array => [
                'id' => $version->public_id,
                'number' => (int) $version->version_number,
                'status' => $version->status->value,
                'entry_count' => (int) $version->entries_count,
                'created_at' => $version->created_at?->toISOString(),
                'submitted_at' => $version->submitted_at?->toISOString(),
                'published_at' => $version->published_at?->toISOString(),
            ])
                ->values()
                ->all(),
            'versionWorkflows' => $versionWorkflows,
            'emptyOfferingCount' => $canManageScheduling ? SubjectOffering::query()
                ->where('organization_id', $currentOrganization->getKey())
                ->where('academic_period_id', $timetable->academic_period_id)
                ->where('status', SubjectOfferingStatus::Active)
                ->doesntHave('components')
                ->count() : 0,
            'offeringComponents' => $canManageScheduling ? OfferingComponent::query()
                ->where('organization_id', $currentOrganization->getKey())
                ->whereHas('offering', fn ($query) => $query
                    ->where('organization_id', $currentOrganization->getKey())
                    ->where('academic_period_id', $timetable->academic_period_id)
                    ->where('status', SubjectOfferingStatus::Active))
                ->whereHas('offering.studentGroup.resource', fn ($query) => $query->where('is_active', true))
                ->with([
                    'offering.subject', 'offering.studentGroup.resource',
                    'instructors' => fn ($query) => $query
                        ->where('faculty_profiles.organization_id', $currentOrganization->getKey())
                        ->whereHas('resource', fn ($query) => $query->where('is_active', true))
                        ->with('resource'),
                ])
                ->orderBy('id')
                ->get()
                ->map(fn (OfferingComponent $component): array => [
                    'id' => $component->public_id,
                    'name' => $component->offering->subject->code.' - '.$component->offering->subject->name.' / '.$component->name,
                    'group' => [
                        'id' => $component->offering->studentGroup->resource->public_id,
                        'name' => $component->offering->studentGroup->name,
                    ],
                    'instructors' => $component->instructors->map(fn (FacultyProfile $faculty): array => [
                        'id' => $faculty->resource->public_id,
                        'name' => $faculty->resource->name,
                    ])->values()->all(),
                    'duration_minutes' => $component->duration_minutes,
                    'delivery_mode' => $component->delivery_mode->value,
                ])->values()->all() : [],
            'canManageVersions' => $canManageVersions,
            'canSubmitVersions' => $canSubmitVersions,
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
            'canManageScheduling' => $canManageScheduling,
        ]);
    }

    /** @return array<string, mixed> */
    private function viewAttributes(TimetableViewData $view): array
    {
        $attributes = $view->toArray()['attributes'];

        return $attributes;
    }
}
