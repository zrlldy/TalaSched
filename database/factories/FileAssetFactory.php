<?php

namespace Database\Factories;

use App\Models\FileAsset;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FileAsset>
 */
class FileAssetFactory extends Factory
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
            'disk' => FileAsset::PRIVATE_DISK,
            'path' => 'organizations/'.Str::lower((string) Str::uuid()).'/workbooks/'.Str::uuid().'.xlsx',
            'original_name' => 'timetable-template.xlsx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'size' => fake()->numberBetween(1_024, 10_240),
            'checksum' => hash('sha256', fake()->uuid()),
            'scan_status' => FileAsset::ScanPending,
        ];
    }
}
