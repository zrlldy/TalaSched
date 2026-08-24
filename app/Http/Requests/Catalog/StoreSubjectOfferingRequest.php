<?php

namespace App\Http\Requests\Catalog;

use App\Enums\SubjectOfferingStatus;
use App\Models\Organization;
use App\Models\SubjectOffering;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSubjectOfferingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = $this->route('current_organization');

        return $organization instanceof Organization
            && $this->user()?->can('create', [SubjectOffering::class, $organization]) === true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $organization = $this->route('current_organization');
        $organizationId = $organization instanceof Organization ? $organization->getKey() : 0;

        return [
            'period_id' => [
                'required',
                'string',
                Rule::exists('academic_periods', 'public_id')->where('organization_id', $organizationId),
            ],
            'subject_id' => [
                'required',
                'string',
                Rule::exists('subjects', 'public_id')->where('organization_id', $organizationId),
            ],
            'student_group_id' => [
                'required',
                'string',
                Rule::exists('student_groups', 'public_id')->where('organization_id', $organizationId),
            ],
            'owning_unit_id' => [
                'nullable',
                'string',
                Rule::exists('academic_units', 'public_id')->where('organization_id', $organizationId),
            ],
            'code' => ['nullable', 'string', 'max:64'],
            'expected_enrollment' => ['nullable', 'integer', 'min:0'],
            'status' => ['required', Rule::enum(SubjectOfferingStatus::class)],
        ];
    }
}
