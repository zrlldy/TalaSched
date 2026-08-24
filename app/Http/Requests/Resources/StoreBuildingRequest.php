<?php

namespace App\Http\Requests\Resources;

use App\Models\Building;
use App\Models\Organization;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBuildingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = $this->route('current_organization');

        return $organization instanceof Organization
            && $this->user()?->can('create', [Building::class, $organization]) === true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'campus_id' => [
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
