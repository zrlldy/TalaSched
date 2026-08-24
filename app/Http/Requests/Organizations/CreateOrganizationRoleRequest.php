<?php

namespace App\Http\Requests\Organizations;

use App\Models\Organization;
use App\Models\Role;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CreateOrganizationRoleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $organization = $this->route('organization');

        return $organization instanceof Organization
            && Gate::allows('create', [Role::class, $organization]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::exists('permissions', 'code')],
        ];
    }
}
