<?php

namespace App\Http\Requests\Approvals;

use App\Approvals\ApprovalWorkflowAuthorizer;
use App\Enums\OrganizationPermission;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreApprovalWorkflowRequest extends FormRequest
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
            'require_distinct_approvers' => ['sometimes', 'boolean'],
            'steps' => ['required', 'array', 'min:1', 'max:12'],
            'steps.*.label' => ['required', 'string', 'max:160'],
            'steps.*.academic_unit_id' => ['nullable', 'uuid'],
            'steps.*.approver_selector_type' => ['required', Rule::in(['permission', 'role'])],
            'steps.*.required_permission' => [
                'nullable',
                Rule::in(array_map(fn (OrganizationPermission $permission): string => $permission->value, OrganizationPermission::cases())),
            ],
            'steps.*.role_codes' => ['nullable', 'array', 'max:12'],
            'steps.*.role_codes.*' => ['string', 'max:80'],
            'steps.*.minimum_approvals' => ['required', 'integer', 'min:1', 'max:20'],
            'steps.*.allow_self_approval' => ['sometimes', 'boolean'],
            'steps.*.signatory_slot' => ['nullable', 'string', 'max:64'],
        ];
    }
}
