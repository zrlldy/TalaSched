<?php

namespace App\Http\Controllers;

use App\Catalog\SubjectCatalogService;
use App\Catalog\SubjectOfferingService;
use App\Enums\AcademicPeriodKind;
use App\Enums\AvailabilityKind;
use App\Enums\DeliveryMode;
use App\Enums\FacultyEmploymentType;
use App\Enums\ResourceType;
use App\Enums\SubjectComponentKind;
use App\Enums\SubjectOfferingStatus;
use App\Http\Requests\Catalog\StoreSubjectComponentRequest;
use App\Http\Requests\Catalog\StoreSubjectOfferingRequest;
use App\Http\Requests\Catalog\StoreSubjectRequest;
use App\Http\Requests\Resources\StoreAvailabilityRuleRequest;
use App\Http\Requests\Resources\StoreBuildingRequest;
use App\Http\Requests\Resources\StoreFacultyProfileRequest;
use App\Http\Requests\Resources\StoreFeatureRequest;
use App\Http\Requests\Resources\StoreRoomRequest;
use App\Http\Requests\Resources\StoreRoomTypeRequest;
use App\Models\AcademicPeriod;
use App\Models\AcademicUnit;
use App\Models\Building;
use App\Models\FacultyProfile;
use App\Models\Feature;
use App\Models\Organization;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\SchedulingResource;
use App\Models\StudentGroup;
use App\Models\Subject;
use App\Models\SubjectComponent;
use App\Models\SubjectOffering;
use App\Resources\FacultyProfileService;
use App\Resources\ResourceAvailabilityService;
use App\Resources\ResourceManagementService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ResourceSetupController extends Controller
{
    public function index(Request $request, Organization $currentOrganization): Response
    {
        Gate::forUser($request->user())->authorize('viewAny', [FacultyProfile::class, $currentOrganization]);

        $organizationId = $currentOrganization->getKey();
        $faculty = FacultyProfile::query()
            ->where('organization_id', $organizationId)
            ->with(['resource', 'academicUnits'])
            ->orderBy('id')
            ->get()
            ->map(fn (FacultyProfile $profile): array => [
                'id' => $profile->public_id,
                'name' => $profile->resource->name,
                'resource_id' => $profile->resource->public_id,
                'employee_number' => $profile->employee_number,
                'position' => $profile->position,
                'employment_type' => $profile->employment_type?->value,
                'maximum_daily_minutes' => $profile->maximum_daily_minutes,
                'maximum_weekly_minutes' => $profile->maximum_weekly_minutes,
                'units' => $profile->academicUnits->map(fn (AcademicUnit $unit): array => [
                    'id' => $unit->public_id,
                    'name' => $unit->name,
                ])->values()->all(),
            ])->values()->all();

        $buildings = Building::query()
            ->where('organization_id', $organizationId)
            ->with('campus')
            ->orderBy('code')
            ->get()
            ->map(fn (Building $building): array => [
                'code' => $building->code,
                'name' => $building->name,
                'campus' => $building->campus?->name,
            ])->values()->all();

        $roomTypes = RoomType::query()
            ->where('organization_id', $organizationId)
            ->orderBy('code')
            ->get(['code', 'name'])
            ->map(fn (RoomType $roomType): array => [
                'code' => $roomType->code,
                'name' => $roomType->name,
            ])->values()->all();

        $features = Feature::query()
            ->where('organization_id', $organizationId)
            ->orderBy('code')
            ->get(['code', 'name'])
            ->map(fn (Feature $feature): array => [
                'code' => $feature->code,
                'name' => $feature->name,
            ])->values()->all();

        $rooms = Room::query()
            ->where('organization_id', $organizationId)
            ->with(['resource', 'building', 'roomType', 'features'])
            ->orderBy('code')
            ->get()
            ->map(fn (Room $room): array => [
                'id' => $room->public_id,
                'resource_id' => $room->resource->public_id,
                'resource_name' => $room->resource->name,
                'code' => $room->code,
                'name' => $room->name,
                'building_code' => $room->building?->code,
                'room_type_code' => $room->roomType->code,
                'capacity' => $room->capacity,
                'delivery_mode' => $room->delivery_mode->value,
                'features' => $room->features->map(fn (Feature $feature): array => [
                    'code' => $feature->code,
                    'quantity' => $this->pivotValue($feature, 'quantity'),
                ])->values()->all(),
            ])->values()->all();

        $subjects = Subject::query()
            ->where('organization_id', $organizationId)
            ->with(['components.roomTypes', 'components.features'])
            ->orderBy('code')
            ->get()
            ->map(fn (Subject $subject): array => [
                'id' => $subject->public_id,
                'code' => $subject->code,
                'name' => $subject->name,
                'units' => $subject->units,
                'description' => $subject->description,
                'components' => $subject->components->map(fn (SubjectComponent $component): array => [
                    'kind' => $component->kind->value,
                    'name' => $component->name,
                    'weekly_minutes' => $component->weekly_minutes,
                    'sessions_per_week' => $component->sessions_per_week,
                    'default_duration_minutes' => $component->default_duration_minutes,
                    'minimum_room_capacity' => $component->minimum_room_capacity,
                    'delivery_mode' => $component->delivery_mode->value,
                    'room_types' => $component->roomTypes->pluck('code')->values()->all(),
                    'features' => $component->features->map(fn (Feature $feature): array => [
                        'code' => $feature->code,
                        'minimum_quantity' => $this->pivotValue($feature, 'minimum_quantity'),
                    ])->values()->all(),
                ])->values()->all(),
            ])->values()->all();

        $periods = AcademicPeriod::query()
            ->where('organization_id', $organizationId)
            ->with('academicYear')
            ->orderByDesc('starts_on')
            ->orderBy('sequence')
            ->get()
            ->map(fn (AcademicPeriod $period): array => [
                'id' => $period->public_id,
                'name' => $period->name,
                'kind' => $period->kind->value,
                'year_name' => $period->academicYear->name,
                'starts_on' => $period->starts_on->toDateString(),
                'ends_on' => $period->ends_on->toDateString(),
            ])->values()->all();

        $groups = StudentGroup::query()
            ->where('organization_id', $organizationId)
            ->with('academicYear')
            ->orderBy('code')
            ->get()
            ->map(fn (StudentGroup $group): array => [
                'id' => $group->public_id,
                'label' => $group->code.' / '.$group->name,
                'year_name' => $group->academicYear->name,
            ])->values()->all();

        $units = AcademicUnit::query()
            ->where('organization_id', $organizationId)
            ->with('type')
            ->orderBy('name')
            ->get()
            ->map(fn (AcademicUnit $unit): array => [
                'id' => $unit->public_id,
                'label' => $unit->name.($unit->code ? ' / '.$unit->code : ''),
                'type' => $unit->type->name,
            ])->values()->all();

        $resources = SchedulingResource::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->orderBy('type')
            ->orderBy('name')
            ->get()
            ->map(fn (SchedulingResource $resource): array => [
                'id' => $resource->public_id,
                'name' => $resource->name,
                'type' => $resource->type->value,
                'type_label' => Str::headline($resource->type->value),
            ])->values()->all();

        $offerings = SubjectOffering::query()
            ->where('organization_id', $organizationId)
            ->with(['subject', 'academicPeriod', 'studentGroup', 'components'])
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (SubjectOffering $offering): array => [
                'id' => $offering->public_id,
                'code' => $offering->code,
                'subject_code' => $offering->subject->code,
                'period_name' => $offering->academicPeriod->name,
                'group_label' => $offering->studentGroup->code.' / '.$offering->studentGroup->name,
                'expected_enrollment' => $offering->expected_enrollment,
                'status' => $offering->status->value,
                'components_count' => $offering->components->count(),
            ])->values()->all();

        return Inertia::render('resources/Setup', [
            'faculty' => $faculty,
            'buildings' => $buildings,
            'roomTypes' => $roomTypes,
            'features' => $features,
            'rooms' => $rooms,
            'subjects' => $subjects,
            'offerings' => $offerings,
            'periods' => $periods,
            'groups' => $groups,
            'units' => $units,
            'resources' => $resources,
            'resourceTypes' => array_map(fn (ResourceType $type): array => [
                'value' => $type->value,
                'label' => Str::headline($type->value),
            ], ResourceType::cases()),
            'employmentTypes' => array_map(fn (FacultyEmploymentType $type): array => [
                'value' => $type->value,
                'label' => Str::headline($type->value),
            ], FacultyEmploymentType::cases()),
            'deliveryModes' => array_map(fn (DeliveryMode $mode): array => [
                'value' => $mode->value,
                'label' => Str::headline($mode->value),
            ], DeliveryMode::cases()),
            'componentKinds' => array_map(fn (SubjectComponentKind $kind): array => [
                'value' => $kind->value,
                'label' => Str::headline($kind->value),
            ], SubjectComponentKind::cases()),
            'offeringStatuses' => array_map(fn (SubjectOfferingStatus $status): array => [
                'value' => $status->value,
                'label' => Str::headline($status->value),
            ], SubjectOfferingStatus::cases()),
            'availabilityKinds' => array_map(fn (AvailabilityKind $kind): array => [
                'value' => $kind->value,
                'label' => Str::headline($kind->value),
            ], AvailabilityKind::cases()),
            'periodKinds' => array_map(fn (AcademicPeriodKind $kind): array => [
                'value' => $kind->value,
                'label' => Str::headline($kind->value),
            ], AcademicPeriodKind::cases()),
            'canManageResources' => $request->user()?->can('create', [FacultyProfile::class, $currentOrganization]) === true,
            'canManageCatalog' => $request->user()?->can('create', [Subject::class, $currentOrganization]) === true,
        ]);
    }

    public function storeFaculty(StoreFacultyProfileRequest $request, Organization $currentOrganization, FacultyProfileService $service): RedirectResponse
    {
        $attributes = $request->validated();
        $academicUnitId = $attributes['academic_unit_id'] ?? null;
        unset($attributes['academic_unit_id']);

        if (isset($attributes['employment_type'])) {
            $attributes['employment_type'] = FacultyEmploymentType::from($attributes['employment_type']);
        }

        $profile = $service->create($currentOrganization, $attributes['resource_name'], array_diff_key($attributes, ['resource_name' => true]), $request->user());

        if (is_string($academicUnitId) && $academicUnitId !== '') {
            $service->assignToAcademicUnit($currentOrganization, $profile, $this->academicUnit($currentOrganization, $academicUnitId), true, $request->user());
        }

        return $this->redirectWithMessage($currentOrganization, 'Faculty profile created.');
    }

    public function storeRoomType(StoreRoomTypeRequest $request, Organization $currentOrganization, ResourceManagementService $service): RedirectResponse
    {
        $data = $request->validated();
        $service->createRoomType($currentOrganization, $data['code'], $data['name'], actor: $request->user());

        return $this->redirectWithMessage($currentOrganization, 'Room type created.');
    }

    public function storeFeature(StoreFeatureRequest $request, Organization $currentOrganization, ResourceManagementService $service): RedirectResponse
    {
        $data = $request->validated();
        $service->createFeature($currentOrganization, $data['code'], $data['name'], $request->user());

        return $this->redirectWithMessage($currentOrganization, 'Room feature created.');
    }

    public function storeBuilding(StoreBuildingRequest $request, Organization $currentOrganization, ResourceManagementService $service): RedirectResponse
    {
        $data = $request->validated();
        $campus = isset($data['campus_id']) && $data['campus_id'] !== ''
            ? $this->academicUnit($currentOrganization, $data['campus_id'])
            : null;
        $service->createBuilding($currentOrganization, $data['code'], $data['name'], $campus, $request->user());

        return $this->redirectWithMessage($currentOrganization, 'Building created.');
    }

    public function storeRoom(StoreRoomRequest $request, Organization $currentOrganization, ResourceManagementService $service): RedirectResponse
    {
        $data = $request->validated();
        $roomType = RoomType::query()
            ->where('organization_id', $currentOrganization->getKey())
            ->where('code', $data['room_type_code'])
            ->firstOrFail();
        $building = isset($data['building_code']) && $data['building_code'] !== ''
            ? Building::query()
                ->where('organization_id', $currentOrganization->getKey())
                ->where('code', $data['building_code'])
                ->firstOrFail()
            : null;

        $service->createRoom(
            $currentOrganization,
            $data['resource_name'],
            $data['code'],
            $data['name'],
            $roomType,
            $building,
            $data['capacity'] ?? null,
            DeliveryMode::from($data['delivery_mode']),
            $request->user(),
        );

        return $this->redirectWithMessage($currentOrganization, 'Room created.');
    }

    public function storeSubject(StoreSubjectRequest $request, Organization $currentOrganization, SubjectCatalogService $service): RedirectResponse
    {
        $data = $request->validated();
        $service->createSubject(
            $currentOrganization,
            $data['code'],
            $data['name'],
            isset($data['units']) ? (float) $data['units'] : null,
            $data['description'] ?? null,
            $request->user(),
        );

        return $this->redirectWithMessage($currentOrganization, 'Subject created.');
    }

    public function storeSubjectComponent(StoreSubjectComponentRequest $request, Organization $currentOrganization, SubjectCatalogService $service): RedirectResponse
    {
        $data = $request->validated();
        $subject = $this->subject($currentOrganization, $data['subject_id']);
        $component = $service->addComponent(
            $currentOrganization,
            $subject,
            SubjectComponentKind::from($data['kind']),
            $data['name'],
            $data['weekly_minutes'],
            $data['sessions_per_week'],
            $data['default_duration_minutes'],
            $data['minimum_room_capacity'] ?? null,
            DeliveryMode::from($data['delivery_mode']),
            $request->user(),
        );
        $roomTypes = [];
        $features = [];

        if (($data['room_type_code'] ?? '') !== '') {
            $roomTypes[] = RoomType::query()
                ->where('organization_id', $currentOrganization->getKey())
                ->where('code', $data['room_type_code'])
                ->firstOrFail();
        }

        if (($data['feature_code'] ?? '') !== '') {
            $feature = Feature::query()
                ->where('organization_id', $currentOrganization->getKey())
                ->where('code', $data['feature_code'])
                ->firstOrFail();
            $features[] = [
                'feature' => $feature,
                'minimum_quantity' => $data['feature_minimum_quantity'] ?? null,
            ];
        }

        if ($roomTypes !== [] || $features !== []) {
            $service->setRequirements($currentOrganization, $component, $roomTypes, $features, $request->user());
        }

        return $this->redirectWithMessage($currentOrganization, 'Subject component created.');
    }

    public function storeOffering(StoreSubjectOfferingRequest $request, Organization $currentOrganization, SubjectOfferingService $service): RedirectResponse
    {
        $data = $request->validated();
        $owningUnit = isset($data['owning_unit_id']) && $data['owning_unit_id'] !== ''
            ? $this->academicUnit($currentOrganization, $data['owning_unit_id'])
            : null;

        $service->createOffering(
            $currentOrganization,
            $this->period($currentOrganization, $data['period_id']),
            $this->subject($currentOrganization, $data['subject_id']),
            $this->studentGroup($currentOrganization, $data['student_group_id']),
            $data['code'] ?? null,
            $data['expected_enrollment'] ?? 0,
            SubjectOfferingStatus::from($data['status']),
            $owningUnit,
            $request->user(),
        );

        return $this->redirectWithMessage($currentOrganization, 'Subject offering created.');
    }

    public function storeAvailability(StoreAvailabilityRuleRequest $request, Organization $currentOrganization, ResourceAvailabilityService $service): RedirectResponse
    {
        $data = $request->validated();
        $effectiveFrom = isset($data['effective_from']) ? CarbonImmutable::parse($data['effective_from']) : null;
        $effectiveUntil = isset($data['effective_until']) ? CarbonImmutable::parse($data['effective_until']) : null;

        $service->create(
            $currentOrganization,
            $this->resource($currentOrganization, $data['resource_id']),
            AvailabilityKind::from($data['kind']),
            $data['weekday'],
            $data['starts_at_minute'],
            $data['ends_at_minute'],
            isset($data['period_id']) && $data['period_id'] !== '' ? $this->period($currentOrganization, $data['period_id']) : null,
            $effectiveFrom,
            $effectiveUntil,
            $data['priority'] ?? 0,
            $request->user(),
        );

        return $this->redirectWithMessage($currentOrganization, 'Availability rule created.');
    }

    private function redirectWithMessage(Organization $organization, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => __($message)]);

        return to_route('resources.setup', ['current_organization' => $organization->slug]);
    }

    private function academicUnit(Organization $organization, string $publicId): AcademicUnit
    {
        return AcademicUnit::query()
            ->where('organization_id', $organization->getKey())
            ->where('public_id', $publicId)
            ->firstOrFail();
    }

    private function period(Organization $organization, string $publicId): AcademicPeriod
    {
        return AcademicPeriod::query()
            ->where('organization_id', $organization->getKey())
            ->where('public_id', $publicId)
            ->firstOrFail();
    }

    private function studentGroup(Organization $organization, string $publicId): StudentGroup
    {
        return StudentGroup::query()
            ->where('organization_id', $organization->getKey())
            ->where('public_id', $publicId)
            ->firstOrFail();
    }

    private function subject(Organization $organization, string $publicId): Subject
    {
        return Subject::query()
            ->where('organization_id', $organization->getKey())
            ->where('public_id', $publicId)
            ->firstOrFail();
    }

    private function resource(Organization $organization, string $publicId): SchedulingResource
    {
        return SchedulingResource::query()
            ->where('organization_id', $organization->getKey())
            ->where('public_id', $publicId)
            ->where('is_active', true)
            ->firstOrFail();
    }

    private function pivotValue(Feature $feature, string $key): mixed
    {
        $pivot = $feature->getRelationValue('pivot');

        return $pivot instanceof Pivot ? $pivot->getAttribute($key) : null;
    }
}
