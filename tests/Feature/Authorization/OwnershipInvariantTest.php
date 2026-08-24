<?php

use App\Enums\OrganizationRole;
use App\Models\Membership;
use App\Models\MembershipRoleAssignment;
use App\Models\Organization;
use App\Models\User;
use LogicException;

test('a non-owner cannot receive the compatibility owner membership role', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $organization = Organization::factory()->ownedBy($owner)->create();

    expect(fn () => $organization->members()->attach($member, ['role' => OrganizationRole::Owner]))
        ->toThrow(LogicException::class, 'Only the explicit organization owner');
});

test('a non-owner cannot receive the normalized owner role directly', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $organization = Organization::factory()->ownedBy($owner)->create();

    $organization->members()->attach($member, ['role' => OrganizationRole::Member]);
    $membership = Membership::query()
        ->where('organization_id', $organization->id)
        ->where('user_id', $member->id)
        ->firstOrFail();
    $ownerRole = $organization->roles()->where('code', OrganizationRole::Owner->value)->firstOrFail();

    expect(fn () => MembershipRoleAssignment::query()->create([
        'organization_id' => $organization->id,
        'membership_id' => $membership->getKey(),
        'role_id' => $ownerRole->getKey(),
    ]))->toThrow(LogicException::class, 'Only the explicit organization owner');
});

test('a membership cannot be changed to the compatibility owner role directly', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $organization = Organization::factory()->ownedBy($owner)->create();

    $organization->members()->attach($member, ['role' => OrganizationRole::Member]);
    $membership = Membership::query()
        ->where('organization_id', $organization->id)
        ->where('user_id', $member->id)
        ->firstOrFail();

    expect(fn () => $membership->update(['role' => OrganizationRole::Owner]))
        ->toThrow(LogicException::class, 'Only the explicit organization owner');
});

test('organization ownership cannot change without a matching owner membership', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $organization = Organization::factory()->ownedBy($owner)->create();

    $organization->members()->attach($member, ['role' => OrganizationRole::Member]);

    expect(fn () => $organization->update(['owner_user_id' => $member->id]))
        ->toThrow(LogicException::class, 'must be transferred to an existing owner member');
});
