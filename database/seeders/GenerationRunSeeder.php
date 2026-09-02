<?php

namespace Database\Seeders;

use App\Enums\GenerationStatus;
use App\Models\GenerationRun;
use App\Models\TimetableVersion;
use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

class GenerationRunSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        app(TenantContext::class)->forEachOrganization(function (): void {
            $sourceVersion = TimetableVersion::query()->orderBy('id')->first();

            if ($sourceVersion === null) {
                return;
            }

            $sampleRun = GenerationRun::factory()
                ->forSourceVersion($sourceVersion)
                ->make([
                    'status' => GenerationStatus::Cancelled,
                    'cancel_requested_at' => now(),
                    'completed_at' => now(),
                    'diagnostics' => [
                        'source' => 'development-seeder',
                        'reason' => 'Sample generation run for local interface development.',
                    ],
                ]);

            GenerationRun::query()->firstOrCreate(
                [
                    'organization_id' => $sourceVersion->organization_id,
                    'source_timetable_version_id' => $sourceVersion->getKey(),
                    'status' => GenerationStatus::Cancelled->value,
                ],
                [
                    'timetable_id' => $sampleRun->timetable_id,
                    'output_timetable_version_id' => null,
                    'requested_by' => null,
                    'public_id' => $sampleRun->public_id,
                    'input_schema_version' => $sampleRun->input_schema_version,
                    'input_snapshot' => $sampleRun->input_snapshot,
                    'seed' => $sampleRun->seed,
                    'progress_percent' => null,
                    'progress_message' => null,
                    'diagnostics' => $sampleRun->diagnostics,
                    'cancel_requested_at' => $sampleRun->cancel_requested_at,
                    'started_at' => null,
                    'completed_at' => $sampleRun->completed_at,
                    'failed_at' => null,
                ],
            );
        });
    }
}
