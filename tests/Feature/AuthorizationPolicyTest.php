<?php

use App\Enums\OrganizationPermission;
use App\Enums\OrganizationRole;
use App\Models\AcademicPeriod;
use App\Models\AcademicUnit;
use App\Models\AcademicUnitType;
use App\Models\AcademicYear;
use App\Models\FacultyProfile;
use App\Models\Feature;
use App\Models\Membership;
use App\Models\OfferingComponent;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\ResourceAvailabilityRule;
use App\Models\Room;
use App\Models\ScheduleEntry;
use App\Models\SchedulingResource;
use App\Models\SignatoryProfile;
use App\Models\StudentGroup;
use App\Models\Subject;
use App\Models\SubjectOffering;
use App\Models\Timetable;
use App\Models\TimetableVersion;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

dataset('organization-owned policy models', [
    'academic years' => [AcademicYear::class, OrganizationPermission::ManageAcademic],
    'academic periods' => [AcademicPeriod::class, OrganizationPermission::ManageAcademic],
    'academic unit types' => [AcademicUnitType::class, OrganizationPermission::ManageAcademic],
    'academic units' => [AcademicUnit::class, OrganizationPermission::ManageAcademic],
    'student groups' => [StudentGroup::class, OrganizationPermission::ManageAcademic],
    'scheduling resources' => [SchedulingResource::class, OrganizationPermission::ManageResources],
    'faculty profiles' => [FacultyProfile::class, OrganizationPermission::ManageResources],
    'rooms' => [Room::class, OrganizationPermission::ManageResources],
    'features' => [Feature::class, OrganizationPermission::ManageResources],
    'availability rules' => [ResourceAvailabilityRule::class, OrganizationPermission::ManageResources],
    'subjects' => [Subject::class, OrganizationPermission::ManageCatalog],
    'subject offerings' => [SubjectOffering::class, OrganizationPermission::ManageCatalog],
    'offering components' => [OfferingComponent::class, OrganizationPermission::ManageCatalog],
    'timetables' => [Timetable::class, OrganizationPermission::ManageScheduling],
    'timetable versions' => [TimetableVersion::class, OrganizationPermission::ManageScheduling],
    'schedule entries' => [ScheduleEntry::class, OrganizationPermission::ManageScheduling],
]);

test('tenant-owned policies distinguish membership from mutation permission', function (string $model, OrganizationPermission $permission) {
    $owner = User::factory()->withOwnedOrganization()->create();
    $member = User::factory()->create();
    $organization = $owner->currentOrganization;
    $otherOrganization = Organization::factory()->create();

    if ($model === ScheduleEntry::class || $model === Timetable::class) {
        grantManualSchedulingEntitlement($organization);
    }

    if ($model === TimetableVersion::class) {
        grantTimetableVersioningEntitlement($organization);
    }

    $organization->members()->attach($member, ['role' => OrganizationRole::Member]);

    expect(Gate::forUser($owner)->allows('create', [$model, $organization]))->toBeTrue()
        ->and(Gate::forUser($member)->allows('viewAny', [$model, $organization]))->toBeTrue()
        ->and(Gate::forUser($member)->allows('create', [$model, $organization]))->toBeFalse()
        ->and(Gate::forUser($member)->allows('viewAny', [$model, $otherOrganization]))->toBeFalse()
        ->and($owner->hasOrganizationPermission($organization, $permission))->toBeTrue();
})->with('organization-owned policy models');

test('membership and invitation policies protect organization administration', function () {
    $owner = User::factory()->withOwnedOrganization()->create();
    $member = User::factory()->create();
    $organization = $owner->currentOrganization;

    $organization->members()->attach($member, ['role' => OrganizationRole::Member]);
    $membership = Membership::query()
        ->where('organization_id', $organization->id)
        ->where('user_id', $member->id)
        ->firstOrFail();
    $invitation = OrganizationInvitation::factory()->create(['organization_id' => $organization->id]);

    expect(Gate::forUser($owner)->allows('update', $membership))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('delete', $membership))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('update', [$membership, OrganizationRole::Owner]))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('transferOwnership', $organization))->toBeTrue()
        ->and(Gate::forUser($member)->allows('view', $membership))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('create', [OrganizationInvitation::class, $organization]))->toBeTrue()
        ->and(Gate::forUser($member)->allows('create', [OrganizationInvitation::class, $organization]))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('delete', $invitation))->toBeTrue();
});

test('timetable lifecycle actions require the scheduling policy', function () {
    $owner = User::factory()->withOwnedOrganization()->create();
    $member = User::factory()->create();
    $organization = $owner->currentOrganization;
    grantTimetableVersioningEntitlement($organization);
    $organization->members()->attach($member, ['role' => OrganizationRole::Member]);
    $academicYear = AcademicYear::factory()->create(['organization_id' => $organization->id]);
    $academicPeriod = AcademicPeriod::factory()->create([
        'organization_id' => $organization->id,
        'academic_year_id' => $academicYear->id,
    ]);
    $timetable = Timetable::factory()->create([
        'organization_id' => $organization->id,
        'academic_year_id' => $academicYear->id,
        'academic_period_id' => $academicPeriod->id,
    ]);
    $version = TimetableVersion::factory()->create([
        'organization_id' => $organization->id,
        'timetable_id' => $timetable->id,
    ]);

    expect(Gate::forUser($owner)->allows('clone', $version))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('publish', $version))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('rollback', $version))->toBeTrue()
        ->and(Gate::forUser($member)->allows('clone', $version))->toBeFalse()
        ->and(Gate::forUser($member)->allows('publish', $version))->toBeFalse()
        ->and(Gate::forUser($member)->allows('rollback', $version))->toBeFalse();
});

test('signatory profile policies require approval workflow management', function () {
    $owner = User::factory()->withOwnedOrganization()->create();
    $member = User::factory()->create();
    $foreignOwner = User::factory()->withOwnedOrganization()->create();
    $organization = $owner->currentOrganization;
    $organization->members()->attach($member, ['role' => OrganizationRole::Member]);
    $profile = SignatoryProfile::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $owner->id,
    ]);

    expect(Gate::forUser($owner)->allows('viewAny', [SignatoryProfile::class, $organization]))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('create', [SignatoryProfile::class, $organization]))->toBeFalse();

    grantApprovalWorkflowsEntitlement($organization);

    expect(Gate::forUser($owner)->allows('viewAny', [SignatoryProfile::class, $organization]))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('create', [SignatoryProfile::class, $organization]))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('view', $profile))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('update', $profile))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('delete', $profile))->toBeTrue()
        ->and(Gate::forUser($member)->allows('viewAny', [SignatoryProfile::class, $organization]))->toBeFalse()
        ->and(Gate::forUser($member)->allows('update', $profile))->toBeFalse()
        ->and(Gate::forUser($foreignOwner)->allows('view', $profile))->toBeFalse();
});
