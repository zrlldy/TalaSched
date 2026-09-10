<?php

use App\Enums\AcademicHierarchyPreset;
use App\Enums\AcademicPeriodKind;
use App\Enums\AcademicYearStatus;
use App\Enums\CalendarExceptionKind;
use App\Enums\OrganizationRole;
use App\Models\AcademicCalendar;
use App\Models\AcademicPeriod;
use App\Models\AcademicUnit;
use App\Models\AcademicYear;
use App\Models\CalendarException;
use App\Models\StudentGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

test('invalid academic period dates and duplicate sequences return correctable field errors', function (array $changes, string $field): void {
    $owner = User::factory()->withOwnedOrganization()->create();
    $organization = $owner->currentOrganization;
    $year = AcademicYear::factory()->forOrganization($organization)->create(['starts_on' => '2026-08-01', 'ends_on' => '2027-07-31']);
    AcademicPeriod::factory()->forAcademicYear($year)->create(['sequence' => 1, 'starts_on' => '2026-08-01', 'ends_on' => '2026-12-31']);

    $this->actingAs($owner)
        ->from(route('academic.setup', $organization))
        ->post(route('academic.periods.store', [$organization, $year->public_id]), array_merge([
            'name' => 'Second period', 'kind' => 'term', 'sequence' => 2,
            'starts_on' => '2027-01-01', 'ends_on' => '2027-05-31',
        ], $changes))
        ->assertRedirect(route('academic.setup', $organization))
        ->assertSessionHasErrors($field);

    expect($year->periods()->count())->toBe(1)
        ->and(DB::table('audit_events')->where('action', 'academic_period.created')->count())->toBe(0);
})->with([
    'starts before the year' => [['starts_on' => '2026-07-01'], 'starts_on'],
    'ends after the year' => [['ends_on' => '2027-08-01'], 'ends_on'],
    'duplicate sequence' => [['sequence' => 1], 'sequence'],
    'reversed dates' => [['ends_on' => '2026-12-01'], 'ends_on'],
    'overlapping period' => [['starts_on' => '2026-12-15'], 'periods'],
]);

test('academic setup exposes public-id contracts and manages years, periods, and presets', function (): void {
    $owner = User::factory()->withOwnedOrganization()->create();
    $organization = $owner->currentOrganization;

    $this->actingAs($owner)
        ->get(route('academic.setup', $organization))
        ->assertStatus(200)
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('academic/Setup')
            ->where('years', [])
            ->has('presets', count(AcademicHierarchyPreset::cases()))
            ->has('periodKinds', count(AcademicPeriodKind::cases()))
            ->where('canManageAcademic', true));

    $this->actingAs($owner)
        ->post(route('academic.years.store', $organization), [
            'name' => '2026-2027',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-07-31',
        ])
        ->assertRedirect(route('academic.setup', $organization));

    $year = AcademicYear::query()->where('organization_id', $organization->getKey())->firstOrFail();

    expect($year->status)->toBe(AcademicYearStatus::Draft)
        ->and($year->public_id)->not->toBe((string) $year->getKey());

    $createdYearAudit = DB::table('audit_events')
        ->where('action', 'academic_year.created')
        ->firstOrFail();

    expect($createdYearAudit->actor_user_id)->toBe($owner->getKey())
        ->and($createdYearAudit->organization_id)->toBe($organization->getKey())
        ->and($createdYearAudit->subject_id)->toBe($year->public_id)
        ->and(json_decode($createdYearAudit->after, true, flags: JSON_THROW_ON_ERROR)['status'])->toBe(AcademicYearStatus::Draft->value);

    $this->actingAs($owner)
        ->post(route('academic.periods.store', [$organization, $year->public_id]), [
            'name' => 'Term 1',
            'kind' => AcademicPeriodKind::Term->value,
            'sequence' => 1,
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-07-31',
        ])
        ->assertRedirect(route('academic.setup', $organization));

    $period = AcademicPeriod::query()->where('academic_year_id', $year->getKey())->firstOrFail();

    $this->actingAs($owner)
        ->post(route('academic.calendars.store', [$organization, $period->public_id]), [
            'weekday' => 1,
            'starts_at_minute' => 480,
            'ends_at_minute' => 960,
        ])
        ->assertRedirect(route('academic.setup', $organization));

    $this->actingAs($owner)
        ->post(route('academic.exceptions.store', [$organization, $period->public_id]), [
            'date' => '2026-12-25',
            'kind' => CalendarExceptionKind::Holiday->value,
            'name' => 'Christmas holiday',
        ])
        ->assertRedirect(route('academic.setup', $organization));

    expect(AcademicCalendar::query()->where('academic_period_id', $period->getKey())->count())->toBe(1)
        ->and(CalendarException::query()->where('academic_period_id', $period->getKey())->count())->toBe(1);
    expect(DB::table('audit_events')->where('action', 'academic_calendar.saved')->value('actor_user_id'))
        ->toBe($owner->getKey())
        ->and(DB::table('audit_events')->where('action', 'calendar_exception.saved')->value('actor_user_id'))
        ->toBe($owner->getKey());

    $this->actingAs($owner)
        ->post(route('academic.years.activate', [$organization, $year->public_id]))
        ->assertRedirect(route('academic.setup', $organization));

    expect($year->fresh()->status)->toBe(AcademicYearStatus::Active)
        ->and(AcademicPeriod::query()->where('academic_year_id', $year->getKey())->count())->toBe(1);

    $activatedYearAudit = DB::table('audit_events')
        ->where('action', 'academic_year.activated')
        ->firstOrFail();

    expect($activatedYearAudit->actor_user_id)->toBe($owner->getKey())
        ->and(json_decode($activatedYearAudit->before, true, flags: JSON_THROW_ON_ERROR)['status'])->toBe(AcademicYearStatus::Draft->value)
        ->and(json_decode($activatedYearAudit->after, true, flags: JSON_THROW_ON_ERROR)['status'])->toBe(AcademicYearStatus::Active->value)
        ->and(DB::table('audit_events')->where('action', 'academic_period.created')->count())->toBe(1);

    $this->actingAs($owner)
        ->post(route('academic.presets.apply', $organization), [
            'preset' => AcademicHierarchyPreset::University->value,
        ])
        ->assertRedirect(route('academic.setup', $organization));

    expect(DB::table('academic_unit_types')->where('organization_id', $organization->getKey())->count())->toBe(3);
    expect(DB::table('audit_events')->where('action', 'academic_hierarchy_preset.applied')->value('actor_user_id'))
        ->toBe($owner->getKey());

    $college = AcademicUnit::query()->where('code', 'UNIVERSITY-COLLEGE-ARTS')->firstOrFail();

    $this->actingAs($owner)
        ->post(route('academic.units.store', $organization), [
            'type_code' => 'university_program',
            'name' => 'Bachelor of Science',
            'code' => 'UNIVERSITY-PROGRAM-BS',
            'parent_id' => $college->public_id,
        ])
        ->assertRedirect(route('academic.setup', $organization));

    $unit = AcademicUnit::query()->where('code', 'UNIVERSITY-PROGRAM-BS')->firstOrFail();

    expect($unit->public_id)->not->toBe((string) $unit->getKey());
    expect(DB::table('audit_events')->where('action', 'academic_unit.created')->count())->toBe(4);

    $this->actingAs($owner)
        ->post(route('academic.units.archive', [$organization, $unit->public_id]))
        ->assertRedirect(route('academic.setup', $organization));

    expect(AcademicUnit::withTrashed()->findOrFail($unit->getKey())->trashed())->toBeTrue();
    expect(DB::table('audit_events')->where('action', 'academic_unit.archived')->value('actor_user_id'))
        ->toBe($owner->getKey());

    $campus = AcademicUnit::query()->where('code', 'UNIVERSITY-CAMPUS')->firstOrFail();
    $group = StudentGroup::factory()->forAcademicYear($year)->forAcademicUnit($college)->create();

    $this->actingAs($owner)
        ->post(route('academic.groups.dates', [$organization, $group->public_id]), [
            'active_from' => '2026-08-01',
            'active_until' => '2027-07-31',
        ])
        ->assertRedirect(route('academic.setup', $organization));

    $this->actingAs($owner)
        ->post(route('academic.groups.unit', [$organization, $group->public_id]), [
            'academic_unit_id' => $campus->public_id,
        ])
        ->assertRedirect(route('academic.setup', $organization));

    $this->actingAs($owner)
        ->post(route('academic.groups.periods.toggle', [$organization, $group->public_id, $period->public_id]))
        ->assertRedirect(route('academic.setup', $organization));

    expect($group->fresh()->active_from->toDateString())->toBe('2026-08-01')
        ->and($group->fresh()->academic_unit_id)->toBe($campus->getKey())
        ->and(DB::table('student_group_periods')->where('student_group_id', $group->getKey())->count())->toBe(1)
        ->and(DB::table('audit_events')->where('action', 'student_group.active_dates_saved')->value('actor_user_id'))
        ->toBe($owner->getKey())
        ->and(DB::table('audit_events')->where('action', 'student_group.academic_unit_assigned')->value('actor_user_id'))
        ->toBe($owner->getKey())
        ->and(DB::table('audit_events')->where('action', 'student_group.period_enrolled')->value('actor_user_id'))
        ->toBe($owner->getKey());
});

test('student group date forms accept either optional date boundary', function (array $data): void {
    $owner = User::factory()->withOwnedOrganization()->create();
    $organization = $owner->currentOrganization;
    $year = AcademicYear::factory()->forOrganization($organization)->create(['starts_on' => '2026-08-01', 'ends_on' => '2027-07-31']);
    $unit = AcademicUnit::factory()->forOrganization($organization)->create();
    $group = StudentGroup::factory()->forAcademicYear($year)->forAcademicUnit($unit)->create();

    $this->actingAs($owner)->post(route('academic.groups.dates', [$organization, $group->public_id]), $data)
        ->assertSessionHasNoErrors()->assertRedirect();
    expect($group->fresh()->active_from?->toDateString())->toBe(($data['active_from'] ?? '') ?: null)
        ->and($group->fresh()->active_until?->toDateString())->toBe(($data['active_until'] ?? '') ?: null);
})->with([
    'both boundaries' => [['active_from' => '2026-08-01', 'active_until' => '2026-12-31']],
    'start only' => [['active_from' => '2026-08-01', 'active_until' => '']],
    'end only' => [['active_from' => '', 'active_until' => '2026-12-31']],
    'omitted start' => [['active_until' => '2026-12-31']],
    'blank boundaries' => [['active_from' => '', 'active_until' => '']],
]);

test('academic setup mutations require the academic management permission', function (): void {
    $owner = User::factory()->withOwnedOrganization()->create();
    $member = User::factory()->create();
    $organization = $owner->currentOrganization;
    $organization->members()->attach($member, ['role' => OrganizationRole::Member]);

    $this->actingAs($member)
        ->post(route('academic.years.store', $organization), [
            'name' => 'Unauthorized year',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-07-31',
        ])
        ->assertForbidden();
});
