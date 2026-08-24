<?php

namespace App\Http\Requests\Scheduling;

use App\Models\Organization;
use App\Models\Timetable;
use App\Models\TimetableVersion;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class CompareTimetableVersionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = $this->route('current_organization');

        return $organization instanceof Organization
            && $this->user()?->can('viewAny', [TimetableVersion::class, $organization]) === true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'from_version_id' => [
                'required',
                'uuid',
                'different:to_version_id',
                $this->versionExistsRule(),
            ],
            'to_version_id' => [
                'required',
                'uuid',
                $this->versionExistsRule(),
            ],
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

    public function fromVersion(): TimetableVersion
    {
        return $this->version('from_version_id');
    }

    public function toVersion(): TimetableVersion
    {
        return $this->version('to_version_id');
    }

    private function version(string $field): TimetableVersion
    {
        /** @var Organization $organization */
        $organization = $this->route('current_organization');

        return TimetableVersion::query()
            ->where('organization_id', $organization->getKey())
            ->where('timetable_id', $this->timetable()->getKey())
            ->where('public_id', (string) $this->validated($field))
            ->firstOrFail();
    }

    private function versionExistsRule(): Exists
    {
        /** @var Organization $organization */
        $organization = $this->route('current_organization');

        return Rule::exists('timetable_versions', 'public_id')
            ->where('organization_id', $organization->getKey());
    }
}
