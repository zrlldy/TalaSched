<?php

use App\Academic\StudentGroupService;
use App\Audit\AuditLogger;
use App\Enums\AcademicYearStatus;
use App\Enums\OrganizationRole;
use App\Enums\ResourceType;
use App\Models\AcademicUnit;
use App\Models\AcademicYear;
use App\Models\SchedulingResource;
use App\Models\StudentGroup;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->owner = User::factory()->withOwnedOrganization()->create();
    $this->organization = $this->owner->currentOrganization;
    $this->year = AcademicYear::factory()->forOrganization($this->organization)->create();
    $this->unit = AcademicUnit::factory()->forOrganization($this->organization)->create();
    $this->data = ['academic_year_id' => $this->year->public_id, 'academic_unit_id' => $this->unit->public_id, 'code' => 'BSCS-1A', 'name' => 'Computer Science 1A', 'expected_headcount' => 30];
    $this->actingAs($this->owner);
});

test('creating a group atomically pairs its scheduling resource and audit event', function (): void {
    $this->post(route('academic.groups.store', $this->organization), [...$this->data, 'organization_id' => 999, 'scheduling_resource_id' => 999])
        ->assertSessionHasNoErrors()->assertRedirect(route('academic.setup', [$this->organization, 'section' => 'groups']));
    $group = StudentGroup::query()->sole();
    expect($group->organization_id)->toBe($this->organization->id)
        ->and($group->academic_year_id)->toBe($this->year->id)
        ->and($group->academic_unit_id)->toBe($this->unit->id)
        ->and($group->resource->type)->toBe(ResourceType::StudentGroup)
        ->and($group->resource->name)->toBe('Computer Science 1A')
        ->and($group->resource->organization_id)->toBe($this->organization->id)
        ->and($group->expected_headcount)->toBe(30)
        ->and($group->periods()->count())->toBe(0);
    $this->assertDatabaseHas('audit_events', ['organization_id' => $this->organization->id, 'action' => 'student_group.created']);
});

test('invalid group creation cannot leave orphaned scheduling resources', function (string $invalid): void {
    $data = $this->data;
    if ($invalid === 'foreign_year') {
        $data['academic_year_id'] = AcademicYear::factory()->create()->public_id;
    } elseif ($invalid === 'foreign_unit') {
        $data['academic_unit_id'] = AcademicUnit::factory()->create()->public_id;
    } elseif ($invalid === 'closed_year') {
        $this->year->update(['status' => AcademicYearStatus::Closed]);
    } elseif ($invalid === 'archived_unit') {
        $this->unit->delete();
    } elseif ($invalid === 'numeric_id') {
        $data['academic_year_id'] = (string) $this->year->id;
    } elseif ($invalid === 'negative_headcount') {
        $data['expected_headcount'] = -1;
    } else {
        $data['name'] = '  ';
    }
    $resourcesBefore = SchedulingResource::query()->count();
    $this->post(route('academic.groups.store', $this->organization), $data)->assertSessionHasErrors();
    expect(StudentGroup::query()->count())->toBe(0)->and(SchedulingResource::query()->count())->toBe($resourcesBefore);
})->with(['foreign_year', 'foreign_unit', 'closed_year', 'archived_unit', 'numeric_id', 'negative_headcount', 'blank_name']);

test('group codes remain unique per year including archived groups but can repeat across years', function (): void {
    $service = app(StudentGroupService::class);
    $group = $service->create($this->organization, $this->owner, $this->year->public_id, $this->unit->public_id, 'BSCS-1A', 'Computer Science 1A');
    $group->delete();
    $this->post(route('academic.groups.store', $this->organization), $this->data)->assertSessionHasErrors('code');
    expect(SchedulingResource::query()->count())->toBe(1);
    $nextYear = AcademicYear::factory()->forOrganization($this->organization)->create();
    $this->post(route('academic.groups.store', $this->organization), [...$this->data, 'academic_year_id' => $nextYear->public_id])->assertSessionHasNoErrors();
    expect(StudentGroup::withTrashed()->count())->toBe(2)->and(SchedulingResource::query()->count())->toBe(2);
});

test('group creation policies also protect the domain boundary', function (): void {
    $viewer = User::factory()->create();
    $this->organization->members()->attach($viewer, ['role' => OrganizationRole::Member]);
    $this->actingAs($viewer)->post(route('academic.groups.store', $this->organization), $this->data)->assertForbidden();
    expect(fn () => app(StudentGroupService::class)->create($this->organization, $viewer, $this->year->public_id, $this->unit->public_id, 'BSCS-1A', 'Class A'))->toThrow(AuthorizationException::class);
    $this->year->update(['status' => AcademicYearStatus::Closed]);
    expect(fn () => app(StudentGroupService::class)->create($this->organization, $this->owner, $this->year->public_id, $this->unit->public_id, 'BSCS-1A', 'Class A'))->toThrow(ValidationException::class);
    expect(StudentGroup::query()->count())->toBe(0)->and(SchedulingResource::query()->count())->toBe(0);
});

test('group creation rolls back the group and resource if auditing fails', function (): void {
    $this->mock(AuditLogger::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
    expect(fn () => app(StudentGroupService::class)->create($this->organization, $this->owner, $this->year->public_id, $this->unit->public_id, 'BSCS-1A', 'Class A'))->toThrow(RuntimeException::class, 'Audit unavailable');
    expect(StudentGroup::query()->count())->toBe(0)->and(SchedulingResource::query()->count())->toBe(0);
});
