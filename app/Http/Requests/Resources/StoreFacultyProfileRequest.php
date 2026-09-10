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
            'maximum_weekly_minutes' => [
                'nullable',
                'integer',
                'min:1',
                Rule::when($this->filled('maximum_daily_minutes'), 'gte:maximum_daily_minutes'),
            ],
            'academic_unit_id' => [
                'nullable',
                'string',
                Rule::exists('academic_units', 'public_id')->where('organization_id', $this->organizationId()),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'maximum_daily_minutes.min' => 'Enter at least 1 minute, or leave the daily teaching limit blank.',
            'maximum_weekly_minutes.min' => 'Enter at least 1 minute, or leave the weekly teaching limit blank.',
            'maximum_daily_minutes.integer' => 'Enter a whole number of minutes for the daily teaching limit.',
            'maximum_weekly_minutes.integer' => 'Enter a whole number of minutes for the weekly teaching limit.',
            'maximum_weekly_minutes.gte' => 'The weekly teaching limit must be at least the daily teaching limit.',
        ];
    }

    private function organizationId(): int
    {
        $organization = $this->route('current_organization');

        return $organization instanceof Organization ? $organization->getKey() : 0;
    }
}
