<?php

namespace Database\Factories;

use App\Models\ExcelTemplate;
use App\Models\ExcelTemplateVersion;
use App\Models\FileAsset;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExcelTemplateVersion>
 */
class ExcelTemplateVersionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'excel_template_id' => fn (array $attributes): int => ExcelTemplate::factory()
                ->forOrganization(Organization::query()->findOrFail((int) $attributes['organization_id']))
                ->create()
                ->getKey(),
            'file_asset_id' => fn (array $attributes): int => FileAsset::factory()
                ->create([
                    'organization_id' => (int) $attributes['organization_id'],
                    'scan_status' => FileAsset::ScanClean,
                ])
                ->getKey(),
            'version_number' => 1,
            'mapping_schema_version' => 1,
            'mapping' => [
                'worksheet' => 'Schedule',
                'timetable_area' => ['start_cell' => 'B3', 'end_cell' => 'F10'],
                'day_columns' => [],
                'time_rows' => [],
                'schedule_cell' => ['format' => '{{title}}'],
                'placeholders' => [],
                'signatory_fields' => [],
            ],
            'activated_at' => null,
        ];
    }

    public function forTemplate(ExcelTemplate $template, FileAsset $sourceAsset): static
    {
        return $this->state(fn (): array => [
            'organization_id' => $template->organization_id,
            'excel_template_id' => $template->getKey(),
            'file_asset_id' => $sourceAsset->getKey(),
        ]);
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->state(fn (): array => [
            'organization_id' => $organization->getKey(),
        ]);
    }
}
