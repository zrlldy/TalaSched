<?php

namespace App\Http\Requests\Scheduling;

use App\Models\Organization;
use App\Models\ScheduleEntry;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class DeleteScheduleEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = $this->route('current_organization');
        $entry = $this->entry();

        return $organization instanceof Organization
            && $this->user()?->can('delete', $entry) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
        ];
    }

    public function entry(): ScheduleEntry
    {
        /** @var Organization $organization */
        $organization = $this->route('current_organization');

        return ScheduleEntry::query()
            ->where('organization_id', $organization->getKey())
            ->where('public_id', (string) $this->route('schedule_entry'))
            ->firstOrFail();
    }
}
