<?php

namespace App\Http\Requests\Approvals;

use App\Approvals\ApprovalWorkflowAuthorizer;
use App\Models\Organization;
use App\Models\SignatoryProfile;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSignatoryProfileRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $organization = $this->route('current_organization');
        $profile = $this->route('signatory_profile');
        $actor = $this->user();

        if (! $organization instanceof Organization
            || ! $profile instanceof SignatoryProfile
            || $profile->organization_id !== $organization->getKey()
            || ! $actor instanceof User) {
            return false;
        }

        try {
            app(ApprovalWorkflowAuthorizer::class)->authorize($organization, $actor);
        } catch (AuthorizationException) {
            return false;
        }

        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
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
            ],
        ];
    }
}
