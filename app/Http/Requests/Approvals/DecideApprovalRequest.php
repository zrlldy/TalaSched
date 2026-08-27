<?php

namespace App\Http\Requests\Approvals;

use App\Enums\ApprovalDecision;
use App\Models\Organization;
use App\Models\TimetableVersion;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DecideApprovalRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $organization = $this->route('current_organization');
        $version = $this->route('timetable_version');

        return $organization instanceof Organization
            && $version instanceof TimetableVersion
            && $version->organization_id === $organization->getKey()
            && $this->user() instanceof User;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'decision' => [
                'required',
                Rule::in(array_map(fn (ApprovalDecision $decision): string => $decision->value, ApprovalDecision::cases())),
            ],
            'comment' => ['nullable', 'string', 'max:5000'],
            'idempotency_key' => ['required', 'string', 'max:120'],
        ];
    }
}
