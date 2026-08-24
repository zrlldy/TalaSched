<?php

namespace App\Http\Requests\Academic;

use App\Models\AcademicCalendar;
use App\Models\Organization;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreAcademicCalendarRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = $this->route('current_organization');

        return $organization instanceof Organization
            && $this->user()?->can('create', [AcademicCalendar::class, $organization]) === true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'weekday' => ['required', 'integer', 'between:1,7'],
            'starts_at_minute' => ['required', 'integer', 'between:0,1439'],
            'ends_at_minute' => ['required', 'integer', 'between:1,1440', 'gt:starts_at_minute'],
        ];
    }
}
