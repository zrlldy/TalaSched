<?php

namespace App\Http\Requests\Scheduling;

use App\Models\Organization;
use App\Models\Timetable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTimetableRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $organization = $this->route('current_organization');

        return $organization instanceof Organization
            && $this->user()?->can('create', [Timetable::class, $organization]) === true;
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
            'name' => ['required', 'string', 'max:255'],
            'academic_period_id' => ['required', 'uuid', Rule::exists('academic_periods', 'public_id')->where('organization_id', $organization->getKey())],
        ];
    }
}
