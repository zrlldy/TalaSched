<?php

use App\Audit\AuditLogger;
use App\Enums\AcademicYearStatus;
use App\Enums\CapabilityKey;
use App\Enums\OrganizationRole;
use App\Enums\TimetableVersionStatus;
use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\Timetable;
use App\Models\TimetableVersion;
use App\Models\User;
use App\Scheduling\CreateTimetable;
use App\Subscriptions\ProvisionOrganizationSubscription;
use App\Subscriptions\UsageService;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->seed(SubscriptionCatalogSeeder::class);
    $this->owner = User::factory()->withOwnedOrganization()->create();
    $this->organization = $this->owner->currentOrganization->refresh();
    app(ProvisionOrganizationSubscription::class)->handle($this->organization, $this->owner);
    $year = AcademicYear::factory()->forOrganization($this->organization)->create();
    $this->period = AcademicPeriod::factory()->forAcademicYear($year)->create();
    $this->actingAs($this->owner);
});

test('members can discover only their organization timetables and latest versions', function (): void {
    $own = Timetable::factory()->create([
        'organization_id' => $this->organization->id,
        'academic_year_id' => $this->period->academic_year_id,
        'academic_period_id' => $this->period->id,
        'name' => 'First term timetable',
    ]);
    TimetableVersion::factory()->create(['organization_id' => $this->organization->id, 'timetable_id' => $own->id, 'version_number' => 1]);
    $latest = TimetableVersion::factory()->create(['organization_id' => $this->organization->id, 'timetable_id' => $own->id, 'version_number' => 2]);
    Timetable::factory()->create(['name' => 'Private other school']);

    $this->get(route('scheduling.timetables.index', $this->organization))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('scheduling/Index')
        ->has('timetables.data', 1)
        ->where('timetables.data.0.id', $own->public_id)
        ->where('timetables.data.0.version.id', $latest->public_id)
        ->where('timetables.data.0.version.number', 2)
        ->missing('timetables.data.0.organization_id')
        ->where('periods', []));

    $this->get(route('scheduling.timetables.index', ['current_organization' => $this->organization->slug, 'search' => 'no match']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->has('timetables.data', 0));
});

test('creating a timetable saves an initial draft and reserves capacity atomically', function (): void {
    $this->post(route('scheduling.timetables.store', $this->organization), [
        'name' => 'School timetable', 'academic_period_id' => $this->period->public_id,
        'organization_id' => 999, 'timezone' => 'UTC',
    ])->assertSessionHasNoErrors()->assertRedirect();

    $timetable = Timetable::query()->where('organization_id', $this->organization->id)->sole();
    $version = $timetable->versions()->sole();
    expect($timetable->academic_year_id)->toBe($this->period->academic_year_id)
        ->and($timetable->timezone)->toBe($this->organization->timezone)
        ->and($version->status)->toBe(TimetableVersionStatus::Draft)
        ->and($version->version_number)->toBe(1)
        ->and($version->created_by)->toBe($this->owner->id)
        ->and(app(UsageService::class)->current($this->organization, CapabilityKey::MaxActiveTimetables))->toBe(1);

    $this->assertDatabaseHas('audit_events', ['action' => 'timetable.created', 'organization_id' => $this->organization->id]);
});

test('invalid period choices never create a timetable or consume capacity', function (string $kind): void {
    $periodId = $this->period->public_id;
    if ($kind === 'foreign') {
        $periodId = AcademicPeriod::factory()->create()->public_id;
    } elseif ($kind === 'numeric') {
        $periodId = (string) $this->period->id;
    } else {
        $this->period->academicYear()->update(['status' => AcademicYearStatus::Closed]);
    }
    $this->post(route('scheduling.timetables.store', $this->organization), [
        'name' => 'School timetable', 'academic_period_id' => $periodId,
    ])->assertSessionHasErrors('academic_period_id');
    expect(Timetable::query()->where('organization_id', $this->organization->id)->count())->toBe(0)
        ->and(app(UsageService::class)->current($this->organization, CapabilityKey::MaxActiveTimetables))->toBe(0);
})->with(['foreign', 'numeric', 'closed']);

test('duplicate submissions keep one timetable and one usage reservation', function (): void {
    $data = ['name' => 'School timetable', 'academic_period_id' => $this->period->public_id];
    $this->post(route('scheduling.timetables.store', $this->organization), $data)->assertSessionHasNoErrors();
    $this->post(route('scheduling.timetables.store', $this->organization), $data)->assertSessionHasErrors('academic_period_id');
    expect(Timetable::query()->where('organization_id', $this->organization->id)->count())->toBe(1)
        ->and(app(UsageService::class)->current($this->organization, CapabilityKey::MaxActiveTimetables))->toBe(1);
});

test('viewers retain the read path but cannot create timetables', function (): void {
    $viewer = User::factory()->create();
    $this->organization->members()->attach($viewer, ['role' => OrganizationRole::Member]);
    $viewer->switchOrganization($this->organization);
    $this->actingAs($viewer)->get(route('scheduling.timetables.index', $this->organization))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('canCreateTimetable', false)->where('periods', []));
    $this->post(route('scheduling.timetables.store', $this->organization), [
        'name' => 'Forbidden', 'academic_period_id' => $this->period->public_id,
    ])->assertForbidden();

    $outsider = User::factory()->create();
    $this->actingAs($outsider)->get(route('scheduling.timetables.index', $this->organization))->assertForbidden();
});

test('plan capacity is enforced before a timetable is created', function (): void {
    $capabilityId = DB::table('capabilities')->where('code', CapabilityKey::MaxActiveTimetables->value)->value('id');
    DB::table('plan_capability_values')->where('capability_id', $capabilityId)->update(['integer_value' => 0]);

    $this->get(route('scheduling.timetables.index', $this->organization))
        ->assertInertia(fn (Assert $page) => $page->where('capacity.available', false)->where('capacity.limit', 0));
    $this->post(route('scheduling.timetables.store', $this->organization), [
        'name' => 'Over capacity', 'academic_period_id' => $this->period->public_id,
    ])->assertSessionHasErrors('capacity');
    expect(Timetable::query()->where('organization_id', $this->organization->id)->count())->toBe(0);
});

test('disabled scheduling capability blocks creation without blocking discovery', function (): void {
    $capabilityId = DB::table('capabilities')->where('code', CapabilityKey::ManualScheduling->value)->value('id');
    DB::table('plan_capability_values')->where('capability_id', $capabilityId)->update(['boolean_value' => false]);
    $this->get(route('scheduling.timetables.index', $this->organization))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('canCreateTimetable', false));
    $this->post(route('scheduling.timetables.store', $this->organization), [
        'name' => 'No access', 'academic_period_id' => $this->period->public_id,
    ])->assertForbidden();
});

test('an audit failure rolls back the timetable draft and capacity reservation', function (): void {
    $this->mock(AuditLogger::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
    expect(fn () => app(CreateTimetable::class)->handle($this->organization, $this->owner, $this->period->public_id, 'Rolled back'))
        ->toThrow(RuntimeException::class, 'Audit unavailable');
    expect(Timetable::query()->where('organization_id', $this->organization->id)->count())->toBe(0)
        ->and(TimetableVersion::query()->where('organization_id', $this->organization->id)->count())->toBe(0)
        ->and(app(UsageService::class)->current($this->organization, CapabilityKey::MaxActiveTimetables))->toBe(0);
});

test('the dashboard overview counts only records in the selected organization', function (): void {
    AcademicPeriod::factory()->create();
    $this->get(route('dashboard', $this->organization))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('overview.counts.years', 1)
            ->where('overview.counts.periods', 1)
            ->where('overview.counts.timetables', 0)
            ->where('overview.recentTimetables', [])
            ->where('workspacePermissions.academic', true));
});
