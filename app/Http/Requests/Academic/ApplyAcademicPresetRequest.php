<?php

namespace App\Http\Requests\Academic;

use App\Enums\AcademicHierarchyPreset;
use App\Models\AcademicUnitType;
use App\Models\Organization;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApplyAcademicPresetRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = $this->route('current_organization');

        return $organization instanceof Organization
            && $this->user()?->can('create', [AcademicUnitType::class, $organization]) === true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'preset' => ['required', Rule::enum(AcademicHierarchyPreset::class)],
        ];
    }
}
