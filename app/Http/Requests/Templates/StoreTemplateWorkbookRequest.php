<?php

namespace App\Http\Requests\Templates;

use App\Models\FileAsset;
use App\Models\Organization;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreTemplateWorkbookRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = $this->route('current_organization');

        return $organization instanceof Organization
            && $this->user()?->can('create', [FileAsset::class, $organization]) === true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'workbook' => ['required', 'file', 'extensions:xlsx', 'max:10240'],
        ];
    }
}
