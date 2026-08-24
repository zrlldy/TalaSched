<?php

use App\Enums\OrganizationRole;
use App\Enums\SubscriptionStatus;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Support\Facades\DB;

test('organization member roles can be updated by owners', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $organization = Organization::factory()->ownedBy($owner)->create();

    $organization->members()->attach($member, ['role' => OrganizationRole::Member->value]);
    $membership = $organization->memberships()->where('user_id', $member->id)->firstOrFail();

    $response = $this
        ->actingAs($owner)
        ->patch(route('organizations.members.update', [$organization, $membership]), [
            'role' => OrganizationRole::Admin->value,
        ]);

    $response->assertRedirect(route('organizations.edit', $organization));

    expect($organization->members()->where('user_id', $member->id)->first()->pivot->role->value)->toEqual(OrganizationRole::Admin->value);
});

test('organization member roles cannot be updated by non owners', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $organization = Organization::factory()->ownedBy($owner)->create();

    $organization->members()->attach($admin, ['role' => OrganizationRole::Admin->value]);
    $organization->members()->attach($member, ['role' => OrganizationRole::Member->value]);
    $membership = $organization->memberships()->where('user_id', $member->id)->firstOrFail();

    $response = $this
        ->actingAs($admin)
        ->patch(route('organizations.members.update', [$organization, $membership]), [
            'role' => OrganizationRole::Admin->value,
        ]);

    $response->assertForbidden();
});

test('organization members can be removed by owners', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $organization = Organization::factory()->ownedBy($owner)->create();

    $organization->members()->attach($member, ['role' => OrganizationRole::Member->value]);
    $membership = $organization->memberships()->where('user_id', $member->id)->firstOrFail();

    $response = $this
        ->actingAs($owner)
        ->delete(route('organizations.members.destroy', [$organization, $membership]));

    $response->assertRedirect(route('organizations.edit', $organization));

    expect($member->fresh()->belongsToOrganization($organization))->toBeFalse();
});

test('removing a member releases reserved member capacity', function () {
    $this->seed(SubscriptionCatalogSeeder::class);
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $organization = Organization::factory()->ownedBy($owner)->create();

    $organization->members()->attach($member, ['role' => OrganizationRole::Member->value]);

    $timestamp = now();
    DB::table('organization_subscriptions')->insert([
        'organization_id' => $organization->id,
        'plan_id' => DB::table('plans')->where('code', 'starter')->value('id'),
        'status' => SubscriptionStatus::Active->value,
        'period_starts_at' => $timestamp,
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);
    DB::table('usage_counters')->insert([
        'organization_id' => $organization->id,
        'capability_id' => DB::table('capabilities')->where('code', 'max_members')->value('id'),
        'period_starts_on' => '1900-01-01',
        'period_ends_on' => '9999-12-31',
        'quantity' => 2,
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);

    $membership = $organization->memberships()->where('user_id', $member->id)->firstOrFail();

    $this
        ->actingAs($owner)
        ->delete(route('organizations.members.destroy', [$organization, $membership]))
        ->assertRedirect(route('organizations.edit', $organization));

    expect(DB::table('usage_counters')
        ->where('organization_id', $organization->id)
        ->where('capability_id', DB::table('capabilities')->where('code', 'max_members')->value('id'))
        ->value('quantity'))->toBe(1);
});

test('organization members cannot be removed by non owners', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $organization = Organization::factory()->ownedBy($owner)->create();

    $organization->members()->attach($admin, ['role' => OrganizationRole::Admin->value]);
    $organization->members()->attach($member, ['role' => OrganizationRole::Member->value]);
    $membership = $organization->memberships()->where('user_id', $member->id)->firstOrFail();

    $response = $this
        ->actingAs($admin)
        ->delete(route('organizations.members.destroy', [$organization, $membership]));

    $response->assertForbidden();
});

test('organization owner cannot be removed', function () {
    $owner = User::factory()->create();
    $organization = Organization::factory()->ownedBy($owner)->create();

    $membership = $organization->memberships()->where('user_id', $owner->id)->firstOrFail();

    $response = $this
        ->actingAs($owner)
        ->delete(route('organizations.members.destroy', [$organization, $membership]));

    $response->assertForbidden();

    expect($owner->fresh()->belongsToOrganization($organization))->toBeTrue();
});

test('organization member role cannot be set to owner', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $organization = Organization::factory()->ownedBy($owner)->create();

    $organization->members()->attach($member, ['role' => OrganizationRole::Member->value]);
    $membership = $organization->memberships()->where('user_id', $member->id)->firstOrFail();

    $response = $this
        ->actingAs($owner)
        ->patch(route('organizations.members.update', [$organization, $membership]), [
            'role' => OrganizationRole::Owner->value,
        ]);

    $response->assertSessionHasErrors('role');

    expect($organization->members()->where('user_id', $member->id)->first()->pivot->role->value)->toEqual(OrganizationRole::Member->value);
});

test('removed member current organization is cleared when no fallback exists', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $organization = Organization::factory()->ownedBy($owner)->create();

    $organization->members()->attach($member, ['role' => OrganizationRole::Member->value]);
    $membership = $organization->memberships()->where('user_id', $member->id)->firstOrFail();

    $member->update(['current_organization_id' => $organization->id]);

    $this
        ->actingAs($owner)
        ->delete(route('organizations.members.destroy', [$organization, $membership]));

    expect($member->fresh()->current_organization_id)->toBeNull();
});

test('numeric membership keys are not accepted by member routes', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $organization = Organization::factory()->ownedBy($owner)->create();

    $organization->members()->attach($member, ['role' => OrganizationRole::Member->value]);
    $membership = $organization->memberships()->where('user_id', $member->id)->firstOrFail();

    $this->actingAs($owner)
        ->patch(route('organizations.members.update', [
            'organization' => $organization,
            'membership' => $membership->id,
        ]), [
            'role' => OrganizationRole::Admin->value,
        ])
        ->assertNotFound();
});

test('memberships cannot be mutated through another organization route', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $organization = Organization::factory()->ownedBy($owner)->create();
    $otherOrganization = Organization::factory()->create();

    $otherOrganization->members()->attach($member, ['role' => OrganizationRole::Member->value]);
    $otherMembership = $otherOrganization->memberships()->where('user_id', $member->id)->firstOrFail();

    $this->actingAs($owner)
        ->delete(route('organizations.members.destroy', [
            'organization' => $organization,
            'membership' => $otherMembership,
        ]))
        ->assertNotFound();

    expect($member->fresh()->belongsToOrganization($otherOrganization))->toBeTrue();
});
