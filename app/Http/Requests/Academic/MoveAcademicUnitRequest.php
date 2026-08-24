<?php

namespace App\Http\Requests\Academic;

use App\Models\AcademicUnit;
use App\Models\Organization;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MoveAcademicUnitRequest extends FormRequest
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
            'parent_id' => [
                'nullable',
                'string',
                Rule::exists('academic_units', 'public_id')
                    ->where('organization_id', $organization instanceof Organization ? $organization->getKey() : 0),
            ],
        ];
    }
}
