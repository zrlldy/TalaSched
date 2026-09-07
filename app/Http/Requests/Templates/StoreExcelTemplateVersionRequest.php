<?php

namespace App\Http\Requests\Templates;

use App\Models\ExcelTemplateVersion;
use App\Models\Organization;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExcelTemplateVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = $this->route('current_organization');

        return $organization instanceof Organization
            && $this->user()?->can('create', [ExcelTemplateVersion::class, $organization]) === true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'template_id' => [
                'nullable',
                'uuid',
                Rule::exists('excel_templates', 'public_id')->where('organization_id', $this->organizationId()),
            ],
            'name' => ['nullable', 'required_without:template_id', 'string', 'max:255'],
            'file_asset_id' => [
                'required',
                'uuid',
                Rule::exists('file_assets', 'public_id')->where('organization_id', $this->organizationId()),
            ],
            'mapping' => ['required', 'array'],
        ];
    }

    private function organizationId(): int
    {
        $organization = $this->route('current_organization');

        return $organization instanceof Organization ? $organization->getKey() : 0;
    }
}
