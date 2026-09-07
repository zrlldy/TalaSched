<?php

use App\Actions\ResolveTemplatePlaceholders;
use App\Enums\TemplatePlaceholder;
use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\Timetable;
use App\Models\TimetableVersion;
use App\Models\User;
use Carbon\CarbonImmutable;

test('resolves the supported catalog from a tenant-scoped timetable version snapshot', function (): void {
    $owner = User::factory()->withOwnedOrganization()->create();
    $organization = $owner->currentOrganization;
    $organization->update(['timezone' => 'Asia/Manila']);
    $academicYear = AcademicYear::factory()->forOrganization($organization)->create(['name' => '2026-2027']);
    $academicPeriod = AcademicPeriod::factory()->forAcademicYear($academicYear)->create(['name' => 'First semester']);
    $timetable = Timetable::factory()->create([
        'organization_id' => $organization->id,
        'academic_year_id' => $academicYear->id,
        'academic_period_id' => $academicPeriod->id,
        'name' => 'BSIT master timetable',
    ]);
    $version = TimetableVersion::factory()->create([
        'organization_id' => $organization->id,
        'timetable_id' => $timetable->id,
        'created_by' => $owner->id,
        'version_number' => 4,
        'published_at' => '2026-09-03 04:00:00',
    ]);

    $resolver = app(ResolveTemplatePlaceholders::class);
    $values = $resolver->resolve($organization, $version, CarbonImmutable::parse('2026-09-03 12:00:00', 'UTC'));

    expect($resolver->catalog())->toContain([
        'key' => TemplatePlaceholder::TimetableName->value,
        'label' => 'Timetable name',
    ])
        ->and($values)->toMatchArray([
            'organization_name' => $organization->name,
            'organization_timezone' => 'Asia/Manila',
            'academic_year_name' => '2026-2027',
            'academic_period_name' => 'First semester',
            'timetable_name' => 'BSIT master timetable',
            'timetable_version_number' => '4',
            'generated_at' => '2026-09-03T20:00:00+08:00',
        ]);
});
