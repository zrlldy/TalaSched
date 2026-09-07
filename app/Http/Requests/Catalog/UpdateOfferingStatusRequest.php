<?php

namespace App\Http\Requests\Catalog;

use App\Enums\SubjectOfferingStatus;
use App\Models\Organization;
use App\Models\SubjectOffering;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOfferingStatusRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $organization = $this->route('current_organization');

        return $organization instanceof Organization
            && $this->user()?->can('create', [SubjectOffering::class, $organization]) === true;
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
            'offering_id' => ['required', 'uuid', Rule::exists('subject_offerings', 'public_id')->where('organization_id', $organization->getKey())],
            'status' => ['required', Rule::enum(SubjectOfferingStatus::class)],
        ];
    }
}
