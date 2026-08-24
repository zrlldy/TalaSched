<?php

namespace App\Http\Requests\Scheduling;

use App\Models\Organization;
use App\Models\Timetable;
use App\Scheduling\TimetableViewFilters;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;

class TimetableViewRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = $this->route('current_organization');

        return $organization instanceof Organization
            && $this->user()?->can('viewAny', [Timetable::class, $organization]) === true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        /** @var Organization $organization */
        $organization = $this->route('current_organization');

        return [
            'scope' => ['sometimes', Rule::in(['organization', 'teacher', 'student_group', 'room', 'unit'])],
            'version_id' => [
                'nullable',
                'uuid',
                Rule::exists('timetable_versions', 'public_id')
                    ->where('organization_id', $organization->getKey()),
            ],
            'resource_id' => [
                'nullable',
                'uuid',
                Rule::exists('scheduling_resources', 'public_id')
                    ->where('organization_id', $organization->getKey()),
            ],
            'unit_id' => [
                'nullable',
                'uuid',
                Rule::exists('academic_units', 'public_id')
                    ->where('organization_id', $organization->getKey()),
            ],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'weekday' => ['nullable', 'integer', 'between:1,7'],
        ];
    }

    public function timetable(): Timetable
    {
        /** @var Organization $organization */
        $organization = $this->route('current_organization');

        return Timetable::query()
            ->where('organization_id', $organization->getKey())
            ->where('public_id', (string) $this->route('timetable'))
            ->firstOrFail();
    }

    public function filters(): TimetableViewFilters
    {
        $validated = $this->validated();
        $date = null;

        if (isset($validated['date'])) {
            $parsedDate = CarbonImmutable::createFromFormat('!Y-m-d', $validated['date']);

            if (! $parsedDate instanceof CarbonImmutable) {
                throw new LogicException('A validated timetable view date must be parseable.');
            }

            $date = $parsedDate;
        }

        return new TimetableViewFilters(
            scope: $validated['scope'] ?? 'organization',
            versionPublicId: $validated['version_id'] ?? null,
            resourcePublicId: $validated['resource_id'] ?? null,
            unitPublicId: $validated['unit_id'] ?? null,
            date: $date,
            weekday: isset($validated['weekday']) ? (int) $validated['weekday'] : null,
        );
    }
}
