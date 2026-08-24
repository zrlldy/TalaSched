<?php

use App\Actions\Organizations\TransferOrganizationOwnership;
use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

test('organization ownership transfers to an existing member and synchronizes authorization', function () {
    $currentOwner = User::factory()->create();
    $newOwner = User::factory()->create();
    $organization = Organization::factory()->ownedBy($currentOwner)->create();

    $organization->members()->attach($newOwner, ['role' => OrganizationRole::Member]);

    $transferredOrganization = app(TransferOrganizationOwnership::class)
        ->handle($organization, $currentOwner, $newOwner);

    expect($transferredOrganization->owner_user_id)->toBe($newOwner->id)
        ->and($currentOwner->fresh()->ownsOrganization($transferredOrganization))->toBeFalse()
        ->and($newOwner->fresh()->ownsOrganization($transferredOrganization))->toBeTrue()
        ->and($currentOwner->fresh()->organizationRole($transferredOrganization))->toBe(OrganizationRole::Admin)
        ->and($newOwner->fresh()->organizationRole($transferredOrganization))->toBe(OrganizationRole::Owner)
        ->and(DB::table('audit_events')->where('action', 'organization.ownership_transferred')->count())->toBe(1)
        ->and(DB::table('audit_events')->where('action', 'organization.ownership_transferred')->value('actor_user_id'))->toBe($currentOwner->id);
});

test('only the explicit owner can transfer organization ownership', function () {
    $currentOwner = User::factory()->create();
    $member = User::factory()->create();
    $newOwner = User::factory()->create();
    $organization = Organization::factory()->ownedBy($currentOwner)->create();

    $organization->members()->attach($member, ['role' => OrganizationRole::Member]);
    $organization->members()->attach($newOwner, ['role' => OrganizationRole::Member]);

    expect(fn () => app(TransferOrganizationOwnership::class)->handle($organization, $member, $newOwner))
        ->toThrow(AuthorizationException::class);
});

test('ownership transfer requires the new owner to be an existing member', function () {
    $currentOwner = User::factory()->create();
    $newOwner = User::factory()->create();
    $organization = Organization::factory()->ownedBy($currentOwner)->create();

    expect(fn () => app(TransferOrganizationOwnership::class)->handle($organization, $currentOwner, $newOwner))
        ->toThrow(DomainException::class, 'must already be a member')
        ->and($organization->fresh()->owner_user_id)->toBe($currentOwner->id)
        ->and(DB::table('audit_events')->where('action', 'organization.ownership_transferred')->count())->toBe(0);
});

test('ownership transfer rejects the current owner as the new owner', function () {
    $currentOwner = User::factory()->create();
    $organization = Organization::factory()->ownedBy($currentOwner)->create();

    expect(fn () => app(TransferOrganizationOwnership::class)->handle($organization, $currentOwner, $currentOwner))
        ->toThrow(DomainException::class, 'different from the current owner');
});
