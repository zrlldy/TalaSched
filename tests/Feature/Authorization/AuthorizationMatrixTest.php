<?php

use App\Actions\Organizations\CreateOrganizationRole;
use App\Enums\OrganizationPermission;
use App\Enums\OrganizationRole;
use App\Models\Membership;
use App\Models\MembershipRoleAssignment;
use App\Models\Organization;
use App\Models\User;

test('built-in roles resolve to their canonical permission matrix', function (): void {
    $owner = User::factory()->withOwnedOrganization()->create();
    $organization = $owner->currentOrganization;
    $admin = User::factory()->create();
    $member = User::factory()->create();

    $organization->members()->attach($admin, ['role' => OrganizationRole::Admin]);
    $organization->members()->attach($member, ['role' => OrganizationRole::Member]);

    foreach ([
        [OrganizationRole::Owner, $owner],
        [OrganizationRole::Admin, $admin],
        [OrganizationRole::Member, $member],
    ] as [$role, $user]) {
        $actualPermissions = collect(OrganizationPermission::cases())
            ->filter(fn (OrganizationPermission $permission): bool => $user->fresh()->hasOrganizationPermission($organization, $permission))
            ->map(fn (OrganizationPermission $permission): string => $permission->value)
            ->sort()
            ->values()
            ->all();
        $expectedPermissions = collect($role->permissions())
            ->map(fn (OrganizationPermission $permission): string => $permission->value)
            ->sort()
            ->values()
            ->all();

        expect($actualPermissions)->toEqual($expectedPermissions);
    }
});

test('custom role assignments resolve only their selected canonical permissions', function (): void {
    $owner = User::factory()->withOwnedOrganization()->create();
    $organization = $owner->currentOrganization;
    $member = User::factory()->create();

    $organization->members()->attach($member, ['role' => OrganizationRole::Member]);
    $customRole = app(CreateOrganizationRole::class)->handle($organization, 'Scheduling Coordinator', [
        OrganizationPermission::ManageScheduling->value,
        OrganizationPermission::UpdateMember->value,
    ]);
    $membership = Membership::query()
        ->where('organization_id', $organization->id)
        ->where('user_id', $member->id)
        ->firstOrFail();

    MembershipRoleAssignment::query()->create([
        'organization_id' => $organization->id,
        'membership_id' => $membership->getKey(),
        'role_id' => $customRole->getKey(),
        'academic_unit_id' => null,
    ]);

    $actualPermissions = collect(OrganizationPermission::cases())
        ->filter(fn (OrganizationPermission $permission): bool => $member->fresh()->hasOrganizationPermission($organization, $permission))
        ->map(fn (OrganizationPermission $permission): string => $permission->value)
        ->sort()
        ->values()
        ->all();

    expect($actualPermissions)->toEqual(collect([
        OrganizationPermission::ManageScheduling->value,
        OrganizationPermission::UpdateMember->value,
    ])->sort()->values()->all());
});

test('one user resolves independent permission sets for multiple organizations', function (): void {
    $organizationWithAdmin = Organization::factory()->create();
    $organizationWithMember = Organization::factory()->create();
    $user = User::factory()->create();

    $organizationWithAdmin->members()->attach($user, ['role' => OrganizationRole::Admin]);
    $organizationWithMember->members()->attach($user, ['role' => OrganizationRole::Member]);

    $user = $user->fresh();

    expect($user->organizationRole($organizationWithAdmin))->toBe(OrganizationRole::Admin)
        ->and($user->organizationRole($organizationWithMember))->toBe(OrganizationRole::Member)
        ->and($user->hasOrganizationPermission($organizationWithAdmin, OrganizationPermission::ManageScheduling))->toBeTrue()
        ->and($user->hasOrganizationPermission($organizationWithMember, OrganizationPermission::ManageScheduling))->toBeFalse()
        ->and($user->hasOrganizationPermission($organizationWithAdmin, OrganizationPermission::UpdateMember))->toBeFalse()
        ->and($user->hasOrganizationPermission($organizationWithMember, OrganizationPermission::UpdateMember))->toBeFalse();
});
