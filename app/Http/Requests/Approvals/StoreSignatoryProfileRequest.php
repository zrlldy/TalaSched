<?php

namespace App\Http\Requests\Approvals;

use App\Models\Organization;
use App\Models\SignatoryProfile;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreSignatoryProfileRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $organization = $this->route('current_organization');
        $actor = $this->user();

        if (! $organization instanceof Organization || ! $actor instanceof User) {
            return false;
        }

        return $actor->can('create', [SignatoryProfile::class, $organization]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'membership_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:160'],
            'position' => ['required', 'string', 'max:160'],
            'academic_unit_id' => ['nullable', 'uuid'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'signature_image' => [
                'nullable',
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'mimetypes:image/jpeg,image/png,image/webp',
                'max:2048',
                'dimensions:max_width=4096,max_height=4096',
            ],
        ];
    }
}
