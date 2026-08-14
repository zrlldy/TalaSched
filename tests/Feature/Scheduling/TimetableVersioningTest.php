<?php

use App\Enums\AcademicPeriodKind;
use App\Enums\TimetableVersionStatus;
use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\Timetable;
use App\Models\TimetableVersion;
use App\Models\User;
use App\Scheduling\CloneTimetableVersion;
use App\Scheduling\PublishTimetableVersion;

beforeEach(function () {
    $this->user = User::factory()->withOwnedOrganization()->create();
    $this->organization = $this->user->currentOrganization;
    $year = AcademicYear::create([
        'organization_id' => $this->organization->id,
        'name' => '2026-2027',
        'starts_on' => '2026-06-01',
        'ends_on' => '2027-05-31',
        'status' => 'active',
    ]);
    $period = AcademicPeriod::create([
        'organization_id' => $this->organization->id,
        'academic_year_id' => $year->id,
        'name' => 'Term 1',
        'kind' => AcademicPeriodKind::Term,
        'sequence' => 1,
        'starts_on' => '2026-06-01',
        'ends_on' => '2026-09-30',
    ]);
    $this->timetable = Timetable::create([
        'organization_id' => $this->organization->id,
        'academic_year_id' => $year->id,
        'academic_period_id' => $period->id,
        'name' => 'Master Timetable',
        'timezone' => 'Asia/Manila',
        'scheduling_granularity' => 30,
    ]);
});

test('a timetable version is cloned as a new editable snapshot', function () {
    $source = TimetableVersion::create([
        'organization_id' => $this->organization->id,
        'timetable_id' => $this->timetable->id,
        'version_number' => 1,
        'status' => TimetableVersionStatus::Published,
        'created_by' => $this->user->id,
    ]);

    $clone = app(CloneTimetableVersion::class)->handle($source, $this->user);

    expect($clone->version_number)->toBe(2)
        ->and($clone->based_on_version_id)->toBe($source->id)
        ->and($clone->status)->toBe(TimetableVersionStatus::Draft);
});

test('publishing atomically supersedes the previous published version', function () {
    $published = TimetableVersion::create([
        'organization_id' => $this->organization->id,
        'timetable_id' => $this->timetable->id,
        'version_number' => 1,
        'status' => TimetableVersionStatus::Published,
        'created_by' => $this->user->id,
    ]);
    $approved = TimetableVersion::create([
        'organization_id' => $this->organization->id,
        'timetable_id' => $this->timetable->id,
        'version_number' => 2,
        'status' => TimetableVersionStatus::Approved,
        'created_by' => $this->user->id,
    ]);

    $result = app(PublishTimetableVersion::class)->handle($approved, $this->user);

    expect($published->fresh()->status)->toBe(TimetableVersionStatus::Superseded)
        ->and($result->status)->toBe(TimetableVersionStatus::Published)
        ->and($result->published_by)->toBe($this->user->id)
        ->and($result->published_at)->not->toBeNull();
});

test('a draft version cannot be published without approval', function () {
    $draft = TimetableVersion::create([
        'organization_id' => $this->organization->id,
        'timetable_id' => $this->timetable->id,
        'version_number' => 1,
        'status' => TimetableVersionStatus::Draft,
        'created_by' => $this->user->id,
    ]);

    expect(fn () => app(PublishTimetableVersion::class)->handle($draft, $this->user))
        ->toThrow(DomainException::class, 'Only an approved timetable version may be published.');
});
