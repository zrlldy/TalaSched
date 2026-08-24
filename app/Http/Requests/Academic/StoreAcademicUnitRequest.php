<?php

namespace App\Http\Requests\Academic;

use App\Models\AcademicUnit;
use App\Models\Organization;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAcademicUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = $this->route('current_organization');

        return $organization instanceof Organization
            && $this->user()?->can('create', [AcademicUnit::class, $organization]) === true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $organization = $this->route('current_organization');

        return [
            'type_code' => [
                'required',
                'string',
                'max:64',
                Rule::exists('academic_unit_types', 'code')
                    ->where('organization_id', $organization instanceof Organization ? $organization->getKey() : 0),
            ],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:64'],
            'parent_id' => [
                'nullable',
                'string',
                Rule::exists('academic_units', 'public_id')
                    ->where('organization_id', $organization instanceof Organization ? $organization->getKey() : 0),
            ],
            'active_from' => ['nullable', 'date'],
            'active_until' => ['nullable', 'date', 'after_or_equal:active_from'],
        ];
    }
}
