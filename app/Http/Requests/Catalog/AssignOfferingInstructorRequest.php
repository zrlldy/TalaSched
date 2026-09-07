<?php

namespace App\Http\Requests\Catalog;

use App\Models\OfferingComponent;
use App\Models\Organization;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignOfferingInstructorRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $organization = $this->route('current_organization');

        return $organization instanceof Organization
            && $this->user()?->can('create', [OfferingComponent::class, $organization]) === true;
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
            'offering_component_id' => ['required', 'uuid', Rule::exists('offering_components', 'public_id')->where('organization_id', $organization->getKey())],
            'faculty_profile_id' => ['required', 'uuid', Rule::exists('faculty_profiles', 'public_id')->where('organization_id', $organization->getKey())->whereNull('deleted_at')],
            'load_percentage' => ['required', 'integer', 'between:1,100'],
        ];
    }
}
