<?php

namespace App\Http\Requests\Scheduling;

use App\Models\Organization;
use App\Models\Timetable;
use App\Models\TimetableVersion;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;

class SubmitTimetableForApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = $this->route('current_organization');
        $timetable = $this->route('timetable');
        $version = $this->route('timetable_version');

        return $organization instanceof Organization
            && $timetable instanceof Timetable
            && $version instanceof TimetableVersion
            && $timetable->organization_id === $organization->getKey()
            && $version->organization_id === $organization->getKey()
            && $version->timetable_id === $timetable->getKey()
            && $this->user()?->can('submitForApproval', $version) === true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $organization = $this->route('current_organization');

        if (! $organization instanceof Organization) {
            throw new LogicException('A timetable approval request requires an organization route model.');
        }

        return [
            'workflow_id' => [
                'required',
                'uuid',
                Rule::exists('approval_workflows', 'public_id')
                    ->where('organization_id', $organization->getKey())
                    ->where('is_active', true),
            ],
        ];
    }

    public function workflowPublicId(): string
    {
        $workflowId = $this->validated('workflow_id');

        if (! is_string($workflowId)) {
            throw new LogicException('The validated workflow identifier must be a public UUID.');
        }

        return $workflowId;
    }
}
