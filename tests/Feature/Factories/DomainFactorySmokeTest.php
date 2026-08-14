<?php

use App\Enums\ResourceType;
use App\Models\AcademicPeriod;
use App\Models\AcademicUnit;
use App\Models\AcademicUnitType;
use App\Models\AcademicYear;
use App\Models\FacultyProfile;
use App\Models\OfferingComponent;
use App\Models\Room;
use App\Models\ScheduleEntry;
use App\Models\StudentGroup;
use App\Models\Subject;
use App\Models\SubjectOffering;
use App\Models\Timetable;
use App\Models\TimetableVersion;
use Illuminate\Support\Facades\DB;

test('academic factories create tenant-consistent records', function () {
    $year = AcademicYear::factory()->create();
    $period = AcademicPeriod::factory()->create();
    $unitType = AcademicUnitType::factory()->create();
    $unit = AcademicUnit::factory()->create();

    $this->assertModelExists($year);
    $this->assertModelExists($period);
    $this->assertModelExists($unitType);
    $this->assertModelExists($unit);

    expect($period->organization_id)->toBe($period->academicYear->organization_id)
        ->and($period->starts_on->betweenIncluded($period->academicYear->starts_on, $period->academicYear->ends_on))->toBeTrue()
        ->and($unit->organization_id)->toBe($unit->type->organization_id);
});

test('resource factories create matching scheduling resources', function () {
    $faculty = FacultyProfile::factory()->create();
    $room = Room::factory()->create();
    $group = StudentGroup::factory()->create();

    expect($faculty->organization_id)->toBe($faculty->resource->organization_id)
        ->and($faculty->resource->type)->toBe(ResourceType::Faculty)
        ->and($room->organization_id)->toBe($room->resource->organization_id)
        ->and($room->resource->type)->toBe(ResourceType::Room)
        ->and($group->organization_id)->toBe($group->resource->organization_id)
        ->and($group->organization_id)->toBe(AcademicYear::findOrFail($group->academic_year_id)->organization_id)
        ->and($group->organization_id)->toBe(AcademicUnit::findOrFail($group->academic_unit_id)->organization_id)
        ->and($group->resource->type)->toBe(ResourceType::StudentGroup)
        ->and(DB::table('room_types')->where('id', $room->room_type_id)->value('organization_id'))->toBe($room->organization_id);
});

test('catalog factories keep offerings within one tenant and academic period', function () {
    $subject = Subject::factory()->create();
    $offering = SubjectOffering::factory()->create();
    $component = OfferingComponent::factory()->create();

    $offering->load(['studentGroup', 'components']);
    $component->load('offering');

    expect($subject->organization_id)->toBeInt()
        ->and($offering->organization_id)->toBe($offering->studentGroup->organization_id)
        ->and($offering->organization_id)->toBe(Subject::findOrFail($offering->subject_id)->organization_id)
        ->and($offering->organization_id)->toBe(AcademicPeriod::findOrFail($offering->academic_period_id)->organization_id)
        ->and($offering->studentGroup->academic_year_id)->toBe(AcademicPeriod::findOrFail($offering->academic_period_id)->academic_year_id)
        ->and($component->organization_id)->toBe($component->offering->organization_id);
});

test('scheduling factories create a complete tenant-owned entry aggregate', function () {
    $timetable = Timetable::factory()->create();
    $version = TimetableVersion::factory()->create();
    $entry = ScheduleEntry::factory()->create();

    $timetable->load('academicPeriod');
    $version->load('timetable');
    $entry->load(['version', 'offeringComponent.offering', 'resources.resource', 'reservations']);

    expect($timetable->organization_id)->toBe($timetable->academicPeriod->organization_id)
        ->and($timetable->academic_year_id)->toBe($timetable->academicPeriod->academic_year_id)
        ->and($version->organization_id)->toBe($version->timetable->organization_id)
        ->and($entry->organization_id)->toBe($entry->version->organization_id)
        ->and($entry->organization_id)->toBe($entry->offeringComponent->organization_id)
        ->and($entry->resources)->toHaveCount(3)
        ->and($entry->reservations)->toHaveCount(3)
        ->and($entry->resources->pluck('organization_id')->unique()->all())->toBe([$entry->organization_id])
        ->and($entry->resources->pluck('resource.organization_id')->unique()->all())->toBe([$entry->organization_id])
        ->and($entry->reservations->pluck('organization_id')->unique()->all())->toBe([$entry->organization_id]);
});
