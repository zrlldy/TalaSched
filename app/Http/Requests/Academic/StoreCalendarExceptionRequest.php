<?php

namespace App\Http\Requests\Academic;

use App\Enums\CalendarExceptionKind;
use App\Models\CalendarException;
use App\Models\Organization;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCalendarExceptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = $this->route('current_organization');

        return $organization instanceof Organization
            && $this->user()?->can('create', [CalendarException::class, $organization]) === true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date'],
            'kind' => ['required', Rule::enum(CalendarExceptionKind::class)],
            'name' => ['required', 'string', 'max:255'],
            'starts_at_minute' => ['nullable', 'integer', 'between:0,1439'],
            'ends_at_minute' => ['nullable', 'integer', 'between:1,1440', 'gt:starts_at_minute'],
        ];
    }
}
