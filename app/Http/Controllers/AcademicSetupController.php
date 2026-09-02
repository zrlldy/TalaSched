<?php

namespace App\Http\Controllers;

use App\Academic\AcademicCalendarService;
use App\Academic\AcademicHierarchyPresetService;
use App\Academic\AcademicHierarchyService;
use App\Academic\AcademicYearLifecycleService;
use App\Academic\StudentGroupService;
use App\Enums\AcademicHierarchyPreset;
use App\Enums\AcademicPeriodKind;
use App\Enums\CalendarExceptionKind;
use App\Http\Requests\Academic\ApplyAcademicPresetRequest;
use App\Http\Requests\Academic\AssignStudentGroupUnitRequest;
use App\Http\Requests\Academic\MoveAcademicUnitRequest;
use App\Http\Requests\Academic\StoreAcademicCalendarRequest;
use App\Http\Requests\Academic\StoreAcademicPeriodRequest;
use App\Http\Requests\Academic\StoreAcademicUnitRequest;
use App\Http\Requests\Academic\StoreAcademicYearRequest;
use App\Http\Requests\Academic\StoreCalendarExceptionRequest;
use App\Http\Requests\Academic\UpdateStudentGroupDatesRequest;
use App\Models\AcademicPeriod;
use App\Models\AcademicUnit;
use App\Models\AcademicUnitType;
use App\Models\AcademicYear;
use App\Models\Organization;
use App\Models\StudentGroup;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class AcademicSetupController extends Controller
{
    public function index(Request $request, Organization $currentOrganization, AcademicHierarchyService $hierarchy): Response
    {
        Gate::authorize('viewAny', [AcademicYear::class, $currentOrganization]);

        $yearModels = AcademicYear::query()
            ->where('organization_id', $currentOrganization->getKey())
            ->with([
                'periods' => fn ($query) => $query
                    ->with(['calendars', 'calendarExceptions'])
                    ->orderBy('sequence'),
            ])
            ->orderByDesc('starts_on')
            ->get();

        $years = [];

        foreach ($yearModels as $academicYear) {
            $periods = [];

            foreach ($academicYear->periods as $period) {
                $calendars = [];

                foreach ($period->calendars as $calendar) {
                    $calendars[] = [
                        'weekday' => $calendar->weekday,
                        'starts_at_minute' => $calendar->starts_at_minute,
                        'ends_at_minute' => $calendar->ends_at_minute,
                    ];
                }

                $exceptions = [];

                foreach ($period->calendarExceptions as $exception) {
                    $exceptions[] = [
                        'date' => $exception->date->toDateString(),
                        'kind' => $exception->kind->value,
                        'name' => $exception->name,
                        'starts_at_minute' => $exception->starts_at_minute,
                        'ends_at_minute' => $exception->ends_at_minute,
                    ];
                }

                $periods[] = [
                    'id' => $period->public_id,
                    'name' => $period->name,
                    'kind' => $period->kind->value,
                    'kind_label' => Str::headline($period->kind->value),
                    'sequence' => $period->sequence,
                    'starts_on' => $period->starts_on->toDateString(),
                    'ends_on' => $period->ends_on->toDateString(),
                    'calendars' => $calendars,
                    'exceptions' => $exceptions,
                ];
            }

            $years[] = [
                'id' => $academicYear->public_id,
                'name' => $academicYear->name,
                'starts_on' => $academicYear->starts_on->toDateString(),
                'ends_on' => $academicYear->ends_on->toDateString(),
                'status' => $academicYear->status->value,
                'periods' => $periods,
            ];
        }

        $unitTypeModels = AcademicUnitType::query()
            ->where('organization_id', $currentOrganization->getKey())
            ->withCount('units')
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();
        $unitTypes = [];

        foreach ($unitTypeModels as $type) {
            $unitTypes[] = [
                'code' => $type->code,
                'name' => $type->name,
                'units_count' => $type->units_count,
            ];
        }

        $unitModels = $hierarchy->query($currentOrganization)->load(['parent', 'type']);
        $units = [];

        foreach ($unitModels as $unit) {
            $units[] = [
                'id' => $unit->public_id,
                'parent_id' => $unit->parent?->public_id,
                'type_code' => $unit->type->code,
                'type_name' => $unit->type->name,
                'name' => $unit->name,
                'code' => $unit->code,
            ];
        }

        $groupModels = StudentGroup::query()
            ->where('organization_id', $currentOrganization->getKey())
            ->with([
                'academicUnit',
                'periods',
                'academicYear.periods' => fn ($query) => $query->orderBy('sequence'),
            ])
            ->orderBy('name')
            ->get();
        $groups = [];

        foreach ($groupModels as $group) {
            $enrolledPeriodIds = $group->periods->modelKeys();
            $periods = [];

            foreach ($group->academicYear->periods as $period) {
                $periods[] = [
                    'id' => $period->public_id,
                    'name' => $period->name,
                    'enrolled' => in_array($period->getKey(), $enrolledPeriodIds, true),
                ];
            }

            $groups[] = [
                'id' => $group->public_id,
                'code' => $group->code,
                'name' => $group->name,
                'academic_year_id' => $group->academicYear->public_id,
                'year_status' => $group->academicYear->status->value,
                'year_starts_on' => $group->academicYear->starts_on->toDateString(),
                'year_ends_on' => $group->academicYear->ends_on->toDateString(),
                'active_from' => $group->active_from?->toDateString(),
                'active_until' => $group->active_until?->toDateString(),
                'academic_unit' => [
                    'id' => $group->academicUnit->public_id,
                    'name' => $group->academicUnit->name,
                ],
                'periods' => $periods,
            ];
        }

        return Inertia::render('academic/Setup', [
            'years' => $years,
            'presets' => array_map(fn (AcademicHierarchyPreset $preset): array => [
                'value' => $preset->value,
                'label' => $preset->label(),
            ], AcademicHierarchyPreset::cases()),
            'periodKinds' => array_map(fn (AcademicPeriodKind $kind): array => [
                'value' => $kind->value,
                'label' => Str::headline($kind->value),
            ], AcademicPeriodKind::cases()),
            'exceptionKinds' => array_map(fn (CalendarExceptionKind $kind): array => [
                'value' => $kind->value,
                'label' => Str::headline($kind->value),
            ], CalendarExceptionKind::cases()),
            'unitTypes' => $unitTypes,
            'units' => $units,
            'groups' => $groups,
            'summary' => [
                'years' => count($years),
                'unit_types' => AcademicUnitType::query()->where('organization_id', $currentOrganization->getKey())->count(),
                'units' => AcademicUnit::query()->where('organization_id', $currentOrganization->getKey())->count(),
            ],
            'canManageAcademic' => $request->user()?->can('create', [AcademicYear::class, $currentOrganization]) === true,
        ]);
    }

    public function storeYear(StoreAcademicYearRequest $request, Organization $currentOrganization, AcademicYearLifecycleService $lifecycle): RedirectResponse
    {
        $lifecycle->createYear(
            organization: $currentOrganization,
            name: $request->validated('name'),
            startsOn: CarbonImmutable::parse($request->validated('starts_on')),
            endsOn: CarbonImmutable::parse($request->validated('ends_on')),
            actor: $request->user(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Academic year created.')]);

        return to_route('academic.setup', ['current_organization' => $currentOrganization->slug]);
    }

    public function storePeriod(StoreAcademicPeriodRequest $request, Organization $currentOrganization, string $academicYear, AcademicYearLifecycleService $lifecycle): RedirectResponse
    {
        $year = $this->academicYear($currentOrganization, $academicYear);

        $lifecycle->createPeriod(
            organization: $currentOrganization,
            academicYear: $year,
            name: $request->validated('name'),
            kind: AcademicPeriodKind::from($request->validated('kind')),
            sequence: $request->validated('sequence'),
            startsOn: CarbonImmutable::parse($request->validated('starts_on')),
            endsOn: CarbonImmutable::parse($request->validated('ends_on')),
            actor: $request->user(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Academic period created.')]);

        return to_route('academic.setup', ['current_organization' => $currentOrganization->slug]);
    }

    public function activate(Request $request, Organization $currentOrganization, string $academicYear, AcademicYearLifecycleService $lifecycle): RedirectResponse
    {
        $year = $this->academicYear($currentOrganization, $academicYear);
        Gate::forUser($request->user())->authorize('update', $year);
        $lifecycle->activate($currentOrganization, $year, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Academic year activated.')]);

        return to_route('academic.setup', ['current_organization' => $currentOrganization->slug]);
    }

    public function applyPreset(ApplyAcademicPresetRequest $request, Organization $currentOrganization, AcademicHierarchyPresetService $presets): RedirectResponse
    {
        $presets->apply($currentOrganization, AcademicHierarchyPreset::from($request->validated('preset')), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Academic structure preset applied.')]);

        return to_route('academic.setup', ['current_organization' => $currentOrganization->slug]);
    }

    public function storeUnit(StoreAcademicUnitRequest $request, Organization $currentOrganization, AcademicHierarchyService $hierarchy): RedirectResponse
    {
        $parentId = $request->validated('parent_id');
        $parent = is_string($parentId) && $parentId !== ''
            ? $this->academicUnit($currentOrganization, $parentId)
            : null;
        $type = AcademicUnitType::query()
            ->where('organization_id', $currentOrganization->getKey())
            ->where('code', $request->validated('type_code'))
            ->firstOrFail();

        $hierarchy->create(
            organization: $currentOrganization,
            type: $type,
            name: $request->validated('name'),
            code: $request->validated('code'),
            parent: $parent,
            activeFrom: $this->dateOrNull($request->validated('active_from')),
            activeUntil: $this->dateOrNull($request->validated('active_until')),
            actor: $request->user(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Academic unit created.')]);

        return to_route('academic.setup', ['current_organization' => $currentOrganization->slug]);
    }

    public function moveUnit(MoveAcademicUnitRequest $request, Organization $currentOrganization, string $academicUnit, AcademicHierarchyService $hierarchy): RedirectResponse
    {
        $unit = $this->academicUnit($currentOrganization, $academicUnit);
        Gate::forUser($request->user())->authorize('update', $unit);

        $parentId = $request->validated('parent_id');
        $parent = is_string($parentId) && $parentId !== ''
            ? $this->academicUnit($currentOrganization, $parentId)
            : null;

        $hierarchy->move($currentOrganization, $unit, $parent, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Academic unit moved.')]);

        return to_route('academic.setup', ['current_organization' => $currentOrganization->slug]);
    }

    public function archiveUnit(Request $request, Organization $currentOrganization, string $academicUnit, AcademicHierarchyService $hierarchy): RedirectResponse
    {
        $unit = $this->academicUnit($currentOrganization, $academicUnit);
        Gate::forUser($request->user())->authorize('delete', $unit);
        $hierarchy->archive($currentOrganization, $unit, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Academic unit archived.')]);

        return to_route('academic.setup', ['current_organization' => $currentOrganization->slug]);
    }

    public function storeCalendar(StoreAcademicCalendarRequest $request, Organization $currentOrganization, string $academicPeriod, AcademicCalendarService $calendar): RedirectResponse
    {
        $period = $this->academicPeriod($currentOrganization, $academicPeriod);

        $calendar->setOperatingHours(
            organization: $currentOrganization,
            academicPeriod: $period,
            weekday: (int) $request->validated('weekday'),
            startsAtMinute: (int) $request->validated('starts_at_minute'),
            endsAtMinute: (int) $request->validated('ends_at_minute'),
            actor: $request->user(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Operating hours saved.')]);

        return to_route('academic.setup', ['current_organization' => $currentOrganization->slug]);
    }

    public function storeCalendarException(StoreCalendarExceptionRequest $request, Organization $currentOrganization, string $academicPeriod, AcademicCalendarService $calendar): RedirectResponse
    {
        $period = $this->academicPeriod($currentOrganization, $academicPeriod);

        $calendar->setException(
            organization: $currentOrganization,
            academicPeriod: $period,
            date: CarbonImmutable::parse($request->validated('date')),
            kind: CalendarExceptionKind::from($request->validated('kind')),
            name: $request->validated('name'),
            startsAtMinute: $this->minuteOrNull($request->validated('starts_at_minute')),
            endsAtMinute: $this->minuteOrNull($request->validated('ends_at_minute')),
            actor: $request->user(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Calendar exception saved.')]);

        return to_route('academic.setup', ['current_organization' => $currentOrganization->slug]);
    }

    public function updateGroupDates(UpdateStudentGroupDatesRequest $request, Organization $currentOrganization, string $studentGroup, StudentGroupService $groups): RedirectResponse
    {
        $group = $this->studentGroup($currentOrganization, $studentGroup);
        Gate::forUser($request->user())->authorize('update', $group);

        $groups->setActiveDates(
            organization: $currentOrganization,
            studentGroup: $group,
            activeFrom: $this->dateOrNull($request->validated('active_from')),
            activeUntil: $this->dateOrNull($request->validated('active_until')),
            actor: $request->user(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Student-group dates saved.')]);

        return to_route('academic.setup', ['current_organization' => $currentOrganization->slug]);
    }

    public function assignGroupUnit(AssignStudentGroupUnitRequest $request, Organization $currentOrganization, string $studentGroup, StudentGroupService $groups): RedirectResponse
    {
        $group = $this->studentGroup($currentOrganization, $studentGroup);
        Gate::forUser($request->user())->authorize('update', $group);
        $unit = $this->academicUnit($currentOrganization, $request->validated('academic_unit_id'));

        $groups->assignToUnit($currentOrganization, $group, $unit, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Student group assigned.')]);

        return to_route('academic.setup', ['current_organization' => $currentOrganization->slug]);
    }

    public function toggleGroupPeriod(Request $request, Organization $currentOrganization, string $studentGroup, string $academicPeriod, StudentGroupService $groups): RedirectResponse
    {
        $group = $this->studentGroup($currentOrganization, $studentGroup);
        Gate::forUser($request->user())->authorize('update', $group);
        $period = $this->academicPeriod($currentOrganization, $academicPeriod);
        $enrolled = DB::table('student_group_periods')
            ->where('organization_id', $currentOrganization->getKey())
            ->where('student_group_id', $group->getKey())
            ->where('academic_period_id', $period->getKey())
            ->exists();

        if ($enrolled) {
            $groups->unenrollFromPeriod($currentOrganization, $group, $period, $request->user());
        } else {
            $groups->enrollInPeriod($currentOrganization, $group, $period, $request->user());
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $enrolled ? __('Student group unenrolled.') : __('Student group enrolled.')]);

        return to_route('academic.setup', ['current_organization' => $currentOrganization->slug]);
    }

    private function academicYear(Organization $organization, string $publicId): AcademicYear
    {
        return AcademicYear::query()
            ->where('organization_id', $organization->getKey())
            ->where('public_id', $publicId)
            ->firstOrFail();
    }

    private function academicUnit(Organization $organization, string $publicId): AcademicUnit
    {
        return AcademicUnit::query()
            ->where('organization_id', $organization->getKey())
            ->where('public_id', $publicId)
            ->firstOrFail();
    }

    private function academicPeriod(Organization $organization, string $publicId): AcademicPeriod
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

    private function dateOrNull(mixed $date): ?CarbonImmutable
    {
        return is_string($date) && $date !== '' ? CarbonImmutable::parse($date) : null;
    }

    private function minuteOrNull(mixed $minute): ?int
    {
        return is_numeric($minute) ? (int) $minute : null;
    }
}
