<?php

use App\Enums\GenerationStatus;
use App\Models\GenerationRun;
use App\Models\TimetableVersion;
use App\Models\User;
use Database\Seeders\GenerationRunSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

test('generation runs retain a reproducible scheduling snapshot and lifecycle data', function () {
    $sourceVersion = TimetableVersion::factory()->create();
    $outputVersion = TimetableVersion::factory()->create([
        'organization_id' => $sourceVersion->organization_id,
        'timetable_id' => $sourceVersion->timetable_id,
        'version_number' => 2,
    ]);
    $requester = User::factory()->create();

    $run = GenerationRun::factory()
        ->forSourceVersion($sourceVersion)
        ->create([
            'output_timetable_version_id' => $outputVersion->getKey(),
            'requested_by' => $requester->getKey(),
            'status' => GenerationStatus::Running,
            'progress_percent' => 45,
            'progress_message' => 'Evaluating room assignments.',
            'diagnostics' => ['candidate_count' => 8],
            'started_at' => now(),
        ]);

    $run->refresh()->load(['timetable', 'sourceVersion', 'outputVersion', 'requestedBy']);

    expect($run->public_id)->toBeString()->not->toBeEmpty()
        ->and($run->status)->toBe(GenerationStatus::Running)
        ->and($run->input_snapshot)->toMatchArray([
            'schema_version' => 1,
            'timetable_id' => $sourceVersion->timetable->public_id,
            'source_version_id' => $sourceVersion->public_id,
            'seed' => $run->seed,
        ])
        ->and($run->diagnostics)->toBe(['candidate_count' => 8])
        ->and($run->timetable->getKey())->toBe($sourceVersion->timetable_id)
        ->and($run->sourceVersion->getKey())->toBe($sourceVersion->getKey())
        ->and($run->outputVersion?->getKey())->toBe($outputVersion->getKey())
        ->and($run->requestedBy?->getKey())->toBe($requester->getKey());
});

test('generation runs cannot reference timetable versions from another organization', function () {
    $sourceVersion = TimetableVersion::factory()->create();
    $foreignVersion = TimetableVersion::factory()->create();

    expect(fn () => DB::table('generation_runs')->insert([
        'organization_id' => $sourceVersion->organization_id,
        'timetable_id' => $sourceVersion->timetable_id,
        'source_timetable_version_id' => $foreignVersion->getKey(),
        'output_timetable_version_id' => null,
        'requested_by' => null,
        'public_id' => (string) Str::uuid(),
        'input_schema_version' => 1,
        'input_snapshot' => json_encode(['schema_version' => 1], JSON_THROW_ON_ERROR),
        'seed' => 1,
        'status' => GenerationStatus::Pending->value,
        'progress_percent' => null,
        'progress_message' => null,
        'diagnostics' => null,
        'cancel_requested_at' => null,
        'started_at' => null,
        'completed_at' => null,
        'failed_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('the development seeder creates one cancelled sample run per source version', function () {
    $sourceVersion = TimetableVersion::factory()->create();

    $this->seed(GenerationRunSeeder::class);
    $this->seed(GenerationRunSeeder::class);

    $run = GenerationRun::query()
        ->where('organization_id', $sourceVersion->organization_id)
        ->where('source_timetable_version_id', $sourceVersion->getKey())
        ->where('status', GenerationStatus::Cancelled)
        ->firstOrFail();

    expect($run->input_snapshot['source_version_id'])->toBe($sourceVersion->public_id)
        ->and($run->diagnostics)->toMatchArray([
            'source' => 'development-seeder',
        ])
        ->and(GenerationRun::query()
            ->where('organization_id', $sourceVersion->organization_id)
            ->where('source_timetable_version_id', $sourceVersion->getKey())
            ->where('status', GenerationStatus::Cancelled)
            ->count())
        ->toBe(1);
});
