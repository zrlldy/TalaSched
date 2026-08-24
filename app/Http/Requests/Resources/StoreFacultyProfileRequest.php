<?php

namespace App\Http\Requests\Resources;

use App\Enums\FacultyEmploymentType;
use App\Models\FacultyProfile;
use App\Models\Organization;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFacultyProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = $this->route('current_organization');

        return $organization instanceof Organization
            && $this->user()?->can('create', [FacultyProfile::class, $organization]) === true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'resource_name' => ['required', 'string', 'max:255'],
            'employee_number' => ['nullable', 'string', 'max:64'],
            'position' => ['nullable', 'string', 'max:255'],
            'employment_type' => ['nullable', Rule::enum(FacultyEmploymentType::class)],
            'maximum_daily_minutes' => ['nullable', 'integer', 'min:1'],
            'maximum_weekly_minutes' => ['nullable', 'integer', 'min:1', 'gte:maximum_daily_minutes'],
            'academic_unit_id' => [
                'nullable',
                'string',
                Rule::exists('academic_units', 'public_id')->where('organization_id', $this->organizationId()),
            ],
        ];
    }

    private function organizationId(): int
    {
        $organization = $this->route('current_organization');

        return $organization instanceof Organization ? $organization->getKey() : 0;
    }
}
