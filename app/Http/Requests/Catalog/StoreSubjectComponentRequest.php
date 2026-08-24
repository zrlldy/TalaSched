<?php

namespace App\Http\Requests\Catalog;

use App\Enums\DeliveryMode;
use App\Enums\SubjectComponentKind;
use App\Models\Organization;
use App\Models\Subject;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSubjectComponentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = $this->route('current_organization');

        return $organization instanceof Organization
            && $this->user()?->can('create', [Subject::class, $organization]) === true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $organizationId = $this->organizationId();

        return [
            'subject_id' => [
                'required',
                'string',
                Rule::exists('subjects', 'public_id')->where('organization_id', $organizationId),
            ],
            'kind' => ['required', Rule::enum(SubjectComponentKind::class)],
            'name' => ['required', 'string', 'max:255'],
            'weekly_minutes' => ['required', 'integer', 'min:1'],
            'sessions_per_week' => ['required', 'integer', 'min:1'],
            'default_duration_minutes' => ['required', 'integer', 'min:1'],
            'minimum_room_capacity' => ['nullable', 'integer', 'min:1'],
            'delivery_mode' => ['required', Rule::enum(DeliveryMode::class)],
            'room_type_code' => [
                'nullable',
                'string',
                Rule::exists('room_types', 'code')->where('organization_id', $organizationId),
            ],
            'feature_code' => [
                'nullable',
                'string',
                Rule::exists('features', 'code')->where('organization_id', $organizationId),
            ],
            'feature_minimum_quantity' => ['nullable', 'integer', 'min:1'],
        ];
    }

    private function organizationId(): int
    {
        $organization = $this->route('current_organization');

        return $organization instanceof Organization ? $organization->getKey() : 0;
    }
}
