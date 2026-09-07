<?php

namespace Database\Factories;

use App\Enums\ExportRunPurpose;
use App\Enums\ExportRunStatus;
use App\Models\ExcelTemplateVersion;
use App\Models\ExportRun;
use App\Models\Organization;
use App\Models\TimetableVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExportRun>
 */
class ExportRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'timetable_version_id' => TimetableVersion::factory(),
            'organization_id' => fn (array $attributes): int => TimetableVersion::query()
                ->whereKey((int) $attributes['timetable_version_id'])
                ->valueOrFail('organization_id'),
            'excel_template_version_id' => fn (array $attributes): int => ExcelTemplateVersion::factory()
                ->forOrganization(Organization::query()->findOrFail((int) $attributes['organization_id']))
                ->create()
                ->getKey(),
            'artifact_file_asset_id' => null,
            'status' => ExportRunStatus::Pending,
            'purpose' => ExportRunPurpose::Export,
            'input_hash' => hash('sha256', fake()->uuid()),
            'error' => null,
            'requested_by' => null,
            'completed_at' => null,
        ];
    }

    public function forInputs(TimetableVersion $timetableVersion, ExcelTemplateVersion $templateVersion): static
    {
        return $this->state(fn (): array => [
            'organization_id' => $timetableVersion->organization_id,
            'timetable_version_id' => $timetableVersion->getKey(),
            'excel_template_version_id' => $templateVersion->getKey(),
        ]);
    }
}
