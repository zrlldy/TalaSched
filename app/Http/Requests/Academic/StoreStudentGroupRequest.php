<?php

namespace App\Http\Requests\Academic;

use App\Enums\AcademicYearStatus;
use App\Models\Organization;
use App\Models\StudentGroup;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStudentGroupRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $organization = $this->route('current_organization');

        return $organization instanceof Organization
            && $this->user()?->can('create', [StudentGroup::class, $organization]) === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Organization $organization */
        $organization = $this->route('current_organization');

        return [
            'academic_year_id' => ['required', 'uuid', Rule::exists('academic_years', 'public_id')->where('organization_id', $organization->getKey())->whereNot('status', AcademicYearStatus::Closed)],
            'academic_unit_id' => ['required', 'uuid', Rule::exists('academic_units', 'public_id')->where('organization_id', $organization->getKey())->whereNull('deleted_at')],
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'expected_headcount' => ['required', 'integer', 'min:0'],
        ];
    }
}
