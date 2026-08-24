<?php

namespace App\Http\Requests\Resources;

use App\Enums\DeliveryMode;
use App\Models\Organization;
use App\Models\Room;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = $this->route('current_organization');

        return $organization instanceof Organization
            && $this->user()?->can('create', [Room::class, $organization]) === true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $organization = $this->route('current_organization');
        $organizationId = $organization instanceof Organization ? $organization->getKey() : 0;

        return [
            'resource_name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'room_type_code' => [
                'required',
                'string',
                Rule::exists('room_types', 'code')->where('organization_id', $organizationId),
            ],
            'building_code' => [
                'nullable',
                'string',
                Rule::exists('buildings', 'code')->where('organization_id', $organizationId),
            ],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'delivery_mode' => ['required', Rule::enum(DeliveryMode::class)],
        ];
    }
}
