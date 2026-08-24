<?php

namespace App\Http\Requests\Resources;

use App\Enums\AvailabilityKind;
use App\Models\Organization;
use App\Models\ResourceAvailabilityRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAvailabilityRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = $this->route('current_organization');

        return $organization instanceof Organization
            && $this->user()?->can('create', [ResourceAvailabilityRule::class, $organization]) === true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $organizationId = $this->organizationId();

        return [
            'resource_id' => [
                'required',
                'string',
                Rule::exists('scheduling_resources', 'public_id')->where('organization_id', $organizationId),
            ],
            'period_id' => [
                'nullable',
                'string',
                Rule::exists('academic_periods', 'public_id')->where('organization_id', $organizationId),
            ],
            'kind' => ['required', Rule::enum(AvailabilityKind::class)],
            'weekday' => ['required', 'integer', 'between:1,7'],
            'starts_at_minute' => ['required', 'integer', 'min:0', 'max:1439'],
            'ends_at_minute' => ['required', 'integer', 'min:1', 'max:1440', 'gt:starts_at_minute'],
            'effective_from' => ['nullable', 'date'],
            'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'priority' => ['nullable', 'integer', 'min:0'],
        ];
    }

    private function organizationId(): int
    {
        $organization = $this->route('current_organization');

        return $organization instanceof Organization ? $organization->getKey() : 0;
    }
}
