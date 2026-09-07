<?php

namespace App\Http\Requests\Templates;

use App\Enums\ExportRunPurpose;
use App\Models\ExportRun;
use App\Models\Organization;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class QueueTemplateExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = $this->route('current_organization');

        return $organization instanceof Organization
            && $this->user()?->can('create', [ExportRun::class, $organization]) === true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'timetable_version_id' => [
                'required',
                'uuid',
                Rule::exists('timetable_versions', 'public_id')->where('organization_id', $this->organizationId()),
            ],
            'template_version_id' => [
                'required',
                'uuid',
                Rule::exists('excel_template_versions', 'public_id')->where('organization_id', $this->organizationId()),
            ],
            'purpose' => ['required', Rule::enum(ExportRunPurpose::class)],
        ];
    }

    private function organizationId(): int
    {
        $organization = $this->route('current_organization');

        return $organization instanceof Organization ? $organization->getKey() : 0;
    }
}
