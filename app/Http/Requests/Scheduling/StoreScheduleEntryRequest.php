<?php

namespace App\Http\Requests\Scheduling;

use App\Enums\ScheduleResourceRole;
use App\Models\OfferingComponent;
use App\Models\Organization;
use App\Models\ScheduleEntry;
use App\Models\SchedulingResource;
use App\Models\TimetableVersion;
use App\Scheduling\ScheduleEntryData;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;

class StoreScheduleEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = $this->route('current_organization');

        return $organization instanceof Organization
            && $this->user()?->can('create', [ScheduleEntry::class, $organization]) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Organization $organization */
        $organization = $this->route('current_organization');

        return [
            'timetable_version_id' => ['required', 'uuid', Rule::exists('timetable_versions', 'public_id')->where('organization_id', $organization->id)],
            'offering_component_id' => ['required', 'uuid', Rule::exists('offering_components', 'public_id')->where('organization_id', $organization->id)],
            'weekday' => ['required', 'integer', 'between:1,7'],
            'occurrence_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'starts_at_minute' => ['required', 'integer', 'between:0,1439'],
            'ends_at_minute' => ['required', 'integer', 'between:1,1440', 'gt:starts_at_minute'],
            'delivery_mode' => ['sometimes', 'string', Rule::in(['physical', 'online', 'hybrid', 'outdoor'])],
            'notes' => ['nullable', 'string', 'max:2000'],
            'resources' => ['required', 'array', 'min:2'],
            'resources.*.resource_id' => ['required', 'uuid', 'distinct', Rule::exists('scheduling_resources', 'public_id')->where('organization_id', $organization->id)],
            'resources.*.role' => ['required', Rule::enum(ScheduleResourceRole::class)],
        ];
    }

    public function scheduleEntryData(): ScheduleEntryData
    {
        $validated = $this->validated();
        /** @var Organization $organization */
        $organization = $this->route('current_organization');

        $timetableVersionId = TimetableVersion::query()
            ->where('organization_id', $organization->id)
            ->where('public_id', $validated['timetable_version_id'])
            ->firstOrFail(['id'])
            ->getKey();

        $offeringComponentId = OfferingComponent::query()
            ->where('organization_id', $organization->id)
            ->where('public_id', $validated['offering_component_id'])
            ->firstOrFail(['id'])
            ->getKey();

        $resourceIdsByPublicId = SchedulingResource::query()
            ->where('organization_id', $organization->id)
            ->whereIn('public_id', collect($this->resourceAssignments())->pluck('resource_id'))
            ->pluck('id', 'public_id');

        $resources = collect($this->resourceAssignments())
            ->map(fn (array $assignment): array => [
                'resource_id' => (int) $resourceIdsByPublicId->get($assignment['resource_id']),
                'role' => $assignment['role'],
            ])
            ->all();

        return new ScheduleEntryData(
            timetableVersionId: (int) $timetableVersionId,
            offeringComponentId: (int) $offeringComponentId,
            weekday: $validated['weekday'],
            startsAtMinute: $validated['starts_at_minute'],
            endsAtMinute: $validated['ends_at_minute'],
            resources: $resources,
            deliveryMode: $validated['delivery_mode'] ?? 'physical',
            notes: $validated['notes'] ?? null,
            occurrenceDate: isset($validated['occurrence_date'])
                ? CarbonImmutable::createFromFormat('!Y-m-d', $validated['occurrence_date'])
                : null,
        );
    }

    /** @return list<array{resource_id: string, role: string}> */
    private function resourceAssignments(): array
    {
        $assignments = $this->validated('resources');

        if (! is_array($assignments)) {
            throw new LogicException('Validated schedule resources must be an array.');
        }

        $normalized = [];

        foreach ($assignments as $assignment) {
            if (! is_array($assignment)
                || ! is_string($assignment['resource_id'] ?? null)
                || ! is_string($assignment['role'] ?? null)) {
                throw new LogicException('Validated schedule resources must contain public IDs and roles.');
            }

            $normalized[] = [
                'resource_id' => $assignment['resource_id'],
                'role' => $assignment['role'],
            ];
        }

        return $normalized;
    }
}
