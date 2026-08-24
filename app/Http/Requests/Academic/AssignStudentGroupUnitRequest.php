<?php

namespace App\Http\Requests\Academic;

use App\Models\Organization;
use App\Models\StudentGroup;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignStudentGroupUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = $this->route('current_organization');

        return $organization instanceof Organization
            && $this->user()?->can('create', [StudentGroup::class, $organization]) === true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $organization = $this->route('current_organization');

        return [
            'academic_unit_id' => [
                'required',
                'string',
                Rule::exists('academic_units', 'public_id')
                    ->where('organization_id', $organization instanceof Organization ? $organization->getKey() : 0),
            ],
        ];
    }
}
