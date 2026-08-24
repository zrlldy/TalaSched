<?php

namespace App\Scheduling;

use App\Enums\ResourceType;
use App\Enums\ScheduleExceptionAction;
use App\Enums\ScheduleResourceRole;
use App\Models\AcademicUnit;
use App\Models\Organization;
use App\Models\ScheduleEntry;
use App\Models\ScheduleEntryException;
use App\Models\ScheduleEntryResource;
use App\Models\SchedulingResource;
use App\Models\Timetable;
use App\Models\TimetableVersion;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Validation\ValidationException;
use LogicException;

final class TimetableViewQuery
{
    public function handle(
        Organization $organization,
        Timetable $timetable,
        TimetableViewFilters $filters,
    ): TimetableViewData {
        if ((int) $timetable->organization_id !== (int) $organization->getKey()) {
            throw ValidationException::withMessages([
                'timetable' => 'The timetable does not belong to the current organization.',
            ]);
        }

        $version = TimetableVersion::query()
            ->where('organization_id', $organization->getKey())
            ->where('timetable_id', $timetable->getKey())
            ->when(
                $filters->versionPublicId !== null,
                fn (Builder $query): Builder => $query->where('public_id', $filters->versionPublicId),
            )
            ->when(
                $filters->versionPublicId === null,
                fn (Builder $query): Builder => $query
                    ->orderByRaw("CASE status WHEN 'published' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END")
                    ->orderByDesc('version_number'),
            )
            ->with('timetable.academicPeriod')
            ->firstOrFail();

        $period = $version->timetable->academicPeriod;
        $this->validateDateFilter($filters->date, $period->starts_on, $period->ends_on);
        $resource = $this->filterResource($organization, $filters);
        $unit = $this->filterUnit($organization, $filters);

        $entries = ScheduleEntry::query()
            ->where('organization_id', $organization->getKey())
            ->where('timetable_version_id', $version->getKey());

        if ($filters->weekday !== null) {
            $entries->where('weekday', $filters->weekday);
        }

        if ($filters->date !== null) {
            $entries->where('weekday', $filters->date->dayOfWeekIso);
        }

        if ($resource instanceof SchedulingResource) {
            $entries = $this->applyResourceFilter($entries, $filters->scope, $resource);
        }

        if ($unit instanceof AcademicUnit) {
            $entries = $this->applyUnitFilter($entries, $unit);
        }

        $entries = $entries
            ->with([
                'offeringComponent.offering.subject',
                'offeringComponent.offering.studentGroup.academicUnit',
                'offeringComponent.offering.owningAcademicUnit',
                'resources.resource',
                'exceptions' => function ($query) use ($filters, $period): void {
                    if ($filters->date !== null) {
                        $query->whereDate('date', $filters->date->toDateString());
                    } else {
                        $query->whereBetween('date', [
                            $period->starts_on->toDateString(),
                            $period->ends_on->toDateString(),
                        ]);
                    }

                    $query->with('resources');
                },
            ])
            ->orderBy('weekday')
            ->orderBy('starts_at_minute')
            ->orderBy('id')
            ->get();

        return new TimetableViewData(
            scope: $filters->scope,
            date: $filters->date?->toDateString(),
            context: [
                'organization' => [
                    'id' => $organization->public_id,
                    'name' => $organization->name,
                    'slug' => $organization->slug,
                    'timezone' => (string) $timetable->timezone,
                ],
                'timetable' => [
                    'id' => $timetable->public_id,
                    'name' => $timetable->name,
                    'timezone' => (string) $timetable->timezone,
                    'scheduling_granularity' => (int) $timetable->scheduling_granularity,
                ],
                'period' => [
                    'id' => $period->public_id,
                    'name' => $period->name,
                    'starts_on' => $period->starts_on->toDateString(),
                    'ends_on' => $period->ends_on->toDateString(),
                ],
                'version' => [
                    'id' => $version->public_id,
                    'number' => (int) $version->version_number,
                    'status' => $version->status->value,
                    'lock_version' => (int) $version->lock_version,
                ],
            ],
            entries: array_values($entries
                ->map(fn (ScheduleEntry $entry): array => $this->entryData($entry, $filters->date))
                ->values()
                ->all()),
        );
    }

    private function validateDateFilter(
        ?CarbonImmutable $date,
        CarbonInterface $periodStartsOn,
        CarbonInterface $periodEndsOn,
    ): void {
        if ($date === null) {
            return;
        }

        if ($date->lt($periodStartsOn) || $date->gt($periodEndsOn)) {
            throw ValidationException::withMessages([
                'date' => 'The timetable view date must fall within the academic period.',
            ]);
        }
    }

    private function filterResource(
        Organization $organization,
        TimetableViewFilters $filters,
    ): ?SchedulingResource {
        if (! in_array($filters->scope, ['teacher', 'student_group', 'room'], true)) {
            return null;
        }

        if ($filters->resourcePublicId === null) {
            throw ValidationException::withMessages([
                'resource_id' => 'This view requires a scheduling resource.',
            ]);
        }

        $resource = SchedulingResource::query()
            ->where('organization_id', $organization->getKey())
            ->where('public_id', $filters->resourcePublicId)
            ->firstOrFail();
        $expectedType = match ($filters->scope) {
            'teacher' => ResourceType::Faculty,
            'student_group' => ResourceType::StudentGroup,
            'room' => ResourceType::Room,
        };

        if ($resource->type !== $expectedType) {
            throw ValidationException::withMessages([
                'resource_id' => 'The selected resource does not match the requested timetable view.',
            ]);
        }

        return $resource;
    }

    private function filterUnit(
        Organization $organization,
        TimetableViewFilters $filters,
    ): ?AcademicUnit {
        if ($filters->scope !== 'unit') {
            return null;
        }

        if ($filters->unitPublicId === null) {
            throw ValidationException::withMessages([
                'unit_id' => 'This view requires an academic unit.',
            ]);
        }

        return AcademicUnit::query()
            ->where('organization_id', $organization->getKey())
            ->where('public_id', $filters->unitPublicId)
            ->firstOrFail();
    }

    /**
     * @param  Builder<ScheduleEntry>  $query
     * @return Builder<ScheduleEntry>
     */
    private function applyResourceFilter(
        Builder $query,
        string $scope,
        SchedulingResource $resource,
    ): Builder {
        $role = match ($scope) {
            'teacher' => ScheduleResourceRole::Instructor,
            'student_group' => ScheduleResourceRole::StudentGroup,
            'room' => ScheduleResourceRole::Room,
            default => throw new LogicException('A resource filter requires a supported timetable view scope.'),
        };

        return $query->whereHas('resources', fn ($resourceQuery) => $resourceQuery
            ->where('scheduling_resource_id', $resource->getKey())
            ->where('role', $role->value));
    }

    /**
     * @param  Builder<ScheduleEntry>  $query
     * @return Builder<ScheduleEntry>
     */
    private function applyUnitFilter(Builder $query, AcademicUnit $unit): Builder
    {
        return $query->where(function ($entryQuery) use ($unit): void {
            $entryQuery
                ->whereHas(
                    'offeringComponent.offering',
                    fn ($offeringQuery) => $offeringQuery
                        ->where('owning_academic_unit_id', $unit->getKey()),
                )
                ->orWhereHas(
                    'offeringComponent.offering.studentGroup',
                    fn ($groupQuery) => $groupQuery
                        ->where('academic_unit_id', $unit->getKey()),
                );
        });
    }

    /** @return array<string, mixed> */
    private function entryData(ScheduleEntry $entry, ?CarbonImmutable $date): array
    {
        $exception = $date === null ? null : $entry->exceptions->first();
        $isCancelled = $exception?->action === ScheduleExceptionAction::Cancelled;
        $exceptionResources = $exception === null ? collect() : $exception->resources;
        if ($isCancelled) {
            $resources = [];
        } elseif ($exceptionResources->isNotEmpty()) {
            $resources = $exceptionResources
                ->map(fn (SchedulingResource $resource): array => $this->exceptionResourceData($resource))
                ->values()
                ->all();
        } else {
            $resources = $entry->resources
                ->map(fn (ScheduleEntryResource $assignment): array => $this->assignmentData($assignment))
                ->values()
                ->all();
        }

        $startsAtMinute = $exception === null
            ? (int) $entry->starts_at_minute
            : ($exception->starts_at_minute ?? (int) $entry->starts_at_minute);
        $endsAtMinute = $exception === null
            ? (int) $entry->ends_at_minute
            : ($exception->ends_at_minute ?? (int) $entry->ends_at_minute);

        return [
            'id' => $entry->public_id,
            'logical_id' => $entry->logical_id,
            'weekday' => (int) $entry->weekday,
            'date' => $date?->toDateString(),
            'status' => $exception?->action->value ?? 'scheduled',
            'starts_at_minute' => $isCancelled ? null : $startsAtMinute,
            'ends_at_minute' => $isCancelled ? null : $endsAtMinute,
            'delivery_mode' => $entry->delivery_mode,
            'notes' => $entry->notes,
            'lock_version' => (int) $entry->lock_version,
            'offering' => $this->offeringData($entry),
            'resources' => $resources,
            'exceptions' => $entry->exceptions
                ->map(fn (ScheduleEntryException $entryException): array => [
                    'id' => $entryException->public_id,
                    'date' => $entryException->date->toDateString(),
                    'action' => $entryException->action->value,
                    'starts_at_minute' => $entryException->starts_at_minute,
                    'ends_at_minute' => $entryException->ends_at_minute,
                    'reason' => $entryException->reason,
                    'resources' => $entryException->resources
                        ->map(fn (SchedulingResource $resource): array => $this->exceptionResourceData($resource))
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function offeringData(ScheduleEntry $entry): array
    {
        $component = $entry->offeringComponent;
        $offering = $component->offering;
        $studentGroup = $offering->studentGroup;

        return [
            'component' => [
                'id' => $component->public_id,
                'name' => $component->name,
                'kind' => $component->kind->value,
            ],
            'offering' => [
                'id' => $offering->public_id,
                'code' => $offering->code,
                'expected_enrollment' => (int) $offering->expected_enrollment,
            ],
            'subject' => [
                'id' => $offering->subject->public_id,
                'code' => $offering->subject->code,
                'name' => $offering->subject->name,
            ],
            'student_group' => [
                'id' => $studentGroup->public_id,
                'code' => $studentGroup->code,
                'name' => $studentGroup->name,
                'academic_unit' => [
                    'id' => $studentGroup->academicUnit->public_id,
                    'name' => $studentGroup->academicUnit->name,
                    'code' => $studentGroup->academicUnit->code,
                ],
            ],
            'owning_unit' => $offering->owningAcademicUnit === null
                ? null
                : [
                    'id' => $offering->owningAcademicUnit->public_id,
                    'name' => $offering->owningAcademicUnit->name,
                    'code' => $offering->owningAcademicUnit->code,
                ],
        ];
    }

    /** @return array<string, mixed> */
    private function assignmentData(ScheduleEntryResource $assignment): array
    {
        return $this->resourceData($assignment->resource, $assignment->role->value);
    }

    /** @return array<string, mixed> */
    private function exceptionResourceData(SchedulingResource $resource): array
    {
        $pivot = $resource->getRelationValue('pivot');

        if (! $pivot instanceof Pivot) {
            throw new LogicException('Timetable exception resources must include their pivot data.');
        }

        $role = $pivot->getAttribute('role');

        if (! is_string($role)) {
            throw new LogicException('Timetable exception resource pivots must include a role.');
        }

        return $this->resourceData($resource, $role);
    }

    /** @return array<string, mixed> */
    private function resourceData(SchedulingResource $resource, string $role): array
    {
        return [
            'id' => $resource->public_id,
            'name' => $resource->name,
            'type' => $resource->type->value,
            'role' => $role,
        ];
    }
}
