<?php

namespace App\Http\Requests\Scheduling;

use App\Enums\ScheduleExceptionAction;
use App\Enums\ScheduleResourceRole;
use App\Models\Organization;
use App\Models\ScheduleEntry;
use App\Models\SchedulingResource;
use App\Scheduling\ScheduleExceptionData;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;

class StoreScheduleExceptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = $this->route('current_organization');

        return $organization instanceof Organization
            && $this->user()?->can('update', $this->entry()) === true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        /** @var Organization $organization */
        $organization = $this->route('current_organization');

        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'action' => ['required', Rule::enum(ScheduleExceptionAction::class)],
            'starts_at_minute' => [
                'nullable',
                'integer',
                'between:0,1439',
                'required_if:action,'.ScheduleExceptionAction::Rescheduled->value,
            ],
            'ends_at_minute' => [
                'nullable',
                'integer',
                'between:1,1440',
                'gt:starts_at_minute',
                'required_if:action,'.ScheduleExceptionAction::Rescheduled->value,
            ],
            'reason' => ['nullable', 'string', 'max:2000'],
            'resources' => ['nullable', 'array', 'required_if:action,'.ScheduleExceptionAction::Replaced->value],
            'resources.*.resource_id' => [
                'required',
                'uuid',
                'distinct',
                Rule::exists('scheduling_resources', 'public_id')->where('organization_id', $organization->getKey()),
            ],
            'resources.*.role' => ['required', Rule::enum(ScheduleResourceRole::class)],
        ];
    }

    public function entry(): ScheduleEntry
    {
        /** @var Organization $organization */
        $organization = $this->route('current_organization');

        return ScheduleEntry::query()
            ->where('organization_id', $organization->getKey())
            ->where('public_id', (string) $this->route('schedule_entry'))
            ->firstOrFail();
    }

    public function scheduleExceptionData(): ScheduleExceptionData
    {
        $validated = $this->validated();
        $resourceAssignments = $this->resourceAssignments();
        /** @var Organization $organization */
        $organization = $this->route('current_organization');
        $resourceIdsByPublicId = SchedulingResource::query()
            ->where('organization_id', $organization->getKey())
            ->whereIn('public_id', collect($resourceAssignments)->pluck('resource_id'))
            ->pluck('id', 'public_id');

        return new ScheduleExceptionData(
            date: CarbonImmutable::createFromFormat('!Y-m-d', $validated['date']),
            action: ScheduleExceptionAction::from($validated['action']),
            startsAtMinute: isset($validated['starts_at_minute']) ? (int) $validated['starts_at_minute'] : null,
            endsAtMinute: isset($validated['ends_at_minute']) ? (int) $validated['ends_at_minute'] : null,
            resources: collect($resourceAssignments)->map(fn (array $assignment): array => [
                'resource_id' => (int) $resourceIdsByPublicId->get($assignment['resource_id']),
                'role' => $assignment['role'],
            ])->all(),
            reason: $validated['reason'] ?? null,
        );
    }

    /** @return list<array{resource_id: string, role: string}> */
    private function resourceAssignments(): array
    {
        $assignments = $this->validated('resources') ?? [];

        if (! is_array($assignments)) {
            return [];
        }

        $normalized = [];

        foreach ($assignments as $assignment) {
            if (! is_array($assignment)
                || ! is_string($assignment['resource_id'] ?? null)
                || ! is_string($assignment['role'] ?? null)) {
                throw new LogicException('Validated exception resources must contain public IDs and roles.');
            }

            $normalized[] = [
                'resource_id' => $assignment['resource_id'],
                'role' => $assignment['role'],
            ];
        }

        return $normalized;
    }
}
