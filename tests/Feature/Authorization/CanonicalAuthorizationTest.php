<?php

use App\Authorization\OrganizationPermissionResolver;
use App\Enums\OrganizationPermission;
use App\Enums\OrganizationRole;
use App\Models\AcademicUnit;
use App\Models\AcademicUnitClosure;
use App\Models\AcademicUnitType;
use App\Models\Membership;
use App\Models\MembershipRoleAssignment;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CanonicalAuthorizationSeeder;
use Illuminate\Support\Facades\DB;

test('canonical permissions and built-in roles are provisioned idempotently', function () {
    $organization = Organization::factory()->create();
    $member = User::factory()->create();

    $organization->members()->attach($member, ['role' => OrganizationRole::Admin]);

    $adminMembership = Membership::query()
        ->where('organization_id', $organization->id)
        ->where('user_id', $member->id)
        ->firstOrFail();

    expect($adminMembership->roleAssignments()->whereNull('academic_unit_id')->count())->toBe(1);

    $this->seed(CanonicalAuthorizationSeeder::class);

    expect(Permission::query()->count())->toBe(count(OrganizationPermission::cases()))
        ->and($organization->roles()->count())->toBe(count(OrganizationRole::cases()));

    foreach (OrganizationPermission::cases() as $permission) {
        expect(Permission::query()->where('code', $permission->value)->first())
            ->not->toBeNull()
            ->name->toBe($permission->label())
            ->module->toBe($permission->module());
    }

    $roles = $organization->roles()->get()->keyBy('code');

    expect($roles->keys()->sort()->values()->all())->toEqual(['admin', 'member', 'owner']);

    foreach ($roles as $role) {
        expect($role->is_system)->toBeTrue();
    }

    expect($roles->get('owner')->permissions()->pluck('code')->sort()->values()->all())
        ->toEqual(collect(OrganizationPermission::cases())->map(fn (OrganizationPermission $permission): string => $permission->value)->sort()->values()->all())
        ->and($roles->get('admin')->permissions()->pluck('code')->sort()->values()->all())
        ->toEqual([
            OrganizationPermission::ManageAcademic->value,
            OrganizationPermission::ManageCatalog->value,
            OrganizationPermission::CancelInvitation->value,
            OrganizationPermission::CreateInvitation->value,
            OrganizationPermission::UpdateOrganization->value,
            OrganizationPermission::ManageResources->value,
            OrganizationPermission::ManageScheduling->value,
        ])
        ->and($roles->get('member')->permissions()->count())->toBe(0);

    expect($adminMembership->roleAssignments()->where('role_id', $roles->get('admin')->id)->count())->toBe(1);

    $roleCount = Role::query()->count();
    $assignmentCount = $adminMembership->roleAssignments()->count();

    $roles->get('admin')->update(['name' => 'Changed', 'is_system' => false]);

    $this->seed(CanonicalAuthorizationSeeder::class);

    expect(Role::query()->count())->toBe($roleCount)
        ->and($adminMembership->roleAssignments()->count())->toBe($assignmentCount)
        ->and($roles->get('admin')->fresh()->name)->toBe('Administrator')
        ->and($roles->get('admin')->fresh()->is_system)->toBeTrue();
});

test('authorization decisions use normalized assignments instead of the legacy role column', function () {
    $organization = Organization::factory()->create();
    $member = User::factory()->create();

    $organization->members()->attach($member, ['role' => OrganizationRole::Admin]);

    $membership = Membership::query()
        ->where('organization_id', $organization->id)
        ->where('user_id', $member->id)
        ->firstOrFail();

    $adminRole = $organization->roles()->where('code', OrganizationRole::Admin->value)->firstOrFail();
    $memberRole = $organization->roles()->where('code', OrganizationRole::Member->value)->firstOrFail();

    DB::table('organization_members')
        ->where('id', $membership->getKey())
        ->update(['role' => OrganizationRole::Member->value]);

    expect($member->fresh()->hasOrganizationPermission($organization, OrganizationPermission::UpdateOrganization))->toBeTrue();

    MembershipRoleAssignment::query()
        ->where('membership_id', $membership->getKey())
        ->where('role_id', $adminRole->getKey())
        ->update(['role_id' => $memberRole->getKey()]);

    expect($member->fresh()->hasOrganizationPermission($organization, OrganizationPermission::UpdateOrganization))->toBeTrue();

    app(OrganizationPermissionResolver::class)->invalidate($member, $organization);

    expect($member->fresh()->hasOrganizationPermission($organization, OrganizationPermission::UpdateOrganization))->toBeFalse();
});

test('permission resolution reuses the lifecycle cache without further queries', function (): void {
    $owner = User::factory()->withOwnedOrganization()->create();
    $organization = $owner->currentOrganization;
    $resolver = app(OrganizationPermissionResolver::class);

    $resolver->flush();
    DB::enableQueryLog();

    try {
        $firstDecision = $resolver->hasPermission(
            $owner,
            $organization,
            OrganizationPermission::UpdateOrganization,
        );

        DB::flushQueryLog();

        $secondDecision = $resolver->hasPermission(
            $owner,
            $organization,
            OrganizationPermission::UpdateOrganization,
        );
        $cachedQueries = DB::getQueryLog();
    } finally {
        DB::disableQueryLog();
    }

    expect($firstDecision)->toBeTrue()
        ->and($secondDecision)->toBeTrue()
        ->and($cachedQueries)->toBeEmpty();
});

test('scoped role permissions follow academic unit descendants only', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $member = User::factory()->create();

    $organization->members()->attach($member, ['role' => OrganizationRole::Admin]);

    $membership = Membership::query()
        ->where('organization_id', $organization->id)
        ->where('user_id', $member->id)
        ->firstOrFail();
    $adminRole = $organization->roles()->where('code', OrganizationRole::Admin->value)->firstOrFail();

    MembershipRoleAssignment::query()
        ->where('membership_id', $membership->getKey())
        ->delete();

    $unitType = AcademicUnitType::factory()->create(['organization_id' => $organization->id]);
    $root = AcademicUnit::factory()->create([
        'organization_id' => $organization->id,
        'academic_unit_type_id' => $unitType->id,
    ]);
    $child = AcademicUnit::factory()->create([
        'organization_id' => $organization->id,
        'academic_unit_type_id' => $unitType->id,
        'parent_id' => $root->id,
    ]);
    $sibling = AcademicUnit::factory()->create([
        'organization_id' => $organization->id,
        'academic_unit_type_id' => $unitType->id,
    ]);
    $foreignUnitType = AcademicUnitType::factory()->create(['organization_id' => $otherOrganization->id]);
    $foreignUnit = AcademicUnit::factory()->create([
        'organization_id' => $otherOrganization->id,
        'academic_unit_type_id' => $foreignUnitType->id,
    ]);

    MembershipRoleAssignment::query()->create([
        'organization_id' => $organization->id,
        'membership_id' => $membership->getKey(),
        'role_id' => $adminRole->getKey(),
        'academic_unit_id' => $root->id,
    ]);

    AcademicUnitClosure::query()->insert([
        ['organization_id' => $organization->id, 'ancestor_id' => $root->id, 'descendant_id' => $root->id, 'depth' => 0],
        ['organization_id' => $organization->id, 'ancestor_id' => $root->id, 'descendant_id' => $child->id, 'depth' => 1],
        ['organization_id' => $organization->id, 'ancestor_id' => $sibling->id, 'descendant_id' => $sibling->id, 'depth' => 0],
    ]);

    $member = $member->fresh();

    expect($member->hasOrganizationPermission($organization, OrganizationPermission::UpdateOrganization))->toBeFalse()
        ->and($member->hasOrganizationPermission($organization, OrganizationPermission::UpdateOrganization, $root))->toBeTrue()
        ->and($member->hasOrganizationPermission($organization, OrganizationPermission::UpdateOrganization, $child))->toBeTrue()
        ->and($member->hasOrganizationPermission($organization, OrganizationPermission::UpdateOrganization, $sibling))->toBeFalse()
        ->and($member->hasOrganizationPermission($organization, OrganizationPermission::UpdateOrganization, $foreignUnit))->toBeFalse();
});
