<?php

use App\Enums\OrganizationRole;
use App\Enums\SubscriptionStatus;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

test('the organizations index page can be rendered', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('organizations.index'));

    $response->assertOk();
});

test('organizations can be created', function () {
    $this->seed(SubscriptionCatalogSeeder::class);
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->post(route('organizations.store'), [
            'name' => 'Test Organization',
        ]);

    $response->assertRedirect();

    $this->assertDatabaseHas('organizations', [
        'name' => 'Test Organization',
    ]);

    $organization = Organization::where('name', 'Test Organization')->firstOrFail();

    expect($user->fresh()->ownsOrganization($organization))->toBeTrue()
        ->and($user->current_organization_id)->toBe($organization->id)
        ->and($organization->owner_user_id)->toBe($user->id);

    $this->assertDatabaseHas('organization_subscriptions', [
        'organization_id' => $organization->id,
        'plan_id' => DB::table('plans')->where('code', 'starter')->value('id'),
        'status' => SubscriptionStatus::Active->value,
    ]);

    $subscriptionAuditEvent = DB::table('audit_events')
        ->where('action', 'subscription.provisioned')
        ->first();

    expect($subscriptionAuditEvent)->not->toBeNull()
        ->and($subscriptionAuditEvent->organization_id)->toBe($organization->id)
        ->and($subscriptionAuditEvent->actor_user_id)->toBe($user->id)
        ->and($subscriptionAuditEvent->subject_id)->toBe($organization->public_id)
        ->and(json_decode($subscriptionAuditEvent->after, true, 512, JSON_THROW_ON_ERROR))
        ->toBe([
            'plan_code' => 'starter',
            'status' => SubscriptionStatus::Active->value,
        ]);

    $auditEvent = DB::table('audit_events')
        ->where('action', 'organization.created')
        ->first();

    expect($auditEvent)->not->toBeNull()
        ->and($auditEvent->organization_id)->toBe($organization->id)
        ->and($auditEvent->actor_user_id)->toBe($user->id)
        ->and($auditEvent->subject_id)->toBe($organization->public_id)
        ->and(json_decode($auditEvent->after, true, 512, JSON_THROW_ON_ERROR))
        ->toBe([
            'name' => 'Test Organization',
            'slug' => 'test-organization',
        ]);
});

test('organization factories create a public identity and matching owner membership', function () {
    $organization = Organization::factory()->create();
    $owner = $organization->owner();

    expect(Str::isUuid($organization->public_id))->toBeTrue()
        ->and($owner)->not->toBeNull()
        ->and($organization->owner_user_id)->toBe($owner->id)
        ->and($organization->memberships()
            ->where('user_id', $owner->id)
            ->where('role', OrganizationRole::Owner->value)
            ->exists())->toBeTrue();
});

test('organization identity columns cannot be null', function () {
    expect(fn () => DB::table('organizations')->insert([
        'name' => 'Missing Identity',
        'slug' => 'missing-identity',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('organization owners cannot be deleted while ownership is retained', function () {
    $organization = Organization::factory()->create();
    $owner = $organization->owner();

    expect(fn () => $owner->delete())->toThrow(QueryException::class)
        ->and($owner->fresh())->not->toBeNull();
});

test('organization owner membership cannot be deleted', function () {
    $organization = Organization::factory()->create();
    $membership = $organization->memberships()
        ->where('user_id', $organization->owner_user_id)
        ->firstOrFail();

    expect(fn () => $membership->delete())->toThrow(LogicException::class)
        ->and($membership->fresh())->not->toBeNull();
});

test('a compatibility owner role cannot delete an organization without explicit ownership', function () {
    $owner = User::factory()->create();
    $legacyOwner = User::factory()->create();
    $organization = Organization::factory()->ownedBy($owner)->create();

    expect(fn () => $organization->members()->attach($legacyOwner, ['role' => OrganizationRole::Owner->value]))
        ->toThrow(LogicException::class, 'Only the explicit organization owner');
});

test('organization slug uses next available suffix', function () {
    $this->seed(SubscriptionCatalogSeeder::class);
    $user = User::factory()->create();

    Organization::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
    Organization::factory()->create(['name' => 'Acme One', 'slug' => 'acme-1']);
    Organization::factory()->create(['name' => 'Acme Ten', 'slug' => 'acme-10']);

    $this
        ->actingAs($user)
        ->post(route('organizations.store'), [
            'name' => 'Acme',
        ]);

    $this->assertDatabaseHas('organizations', [
        'name' => 'Acme',
        'slug' => 'acme-11',
    ]);
});

test('the organization edit page can be rendered', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->ownedBy($user)->create();

    $membership = $organization->memberships()->where('user_id', $user->id)->firstOrFail();

    $response = $this
        ->actingAs($user)
        ->get(route('organizations.edit', $organization));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organizations/Edit')
            ->where('organization.id', $organization->public_id)
            ->where('members.0.id', $membership->public_id)
            ->where('members.0.role', OrganizationRole::Owner->value)
            ->where('members.0.role_label', OrganizationRole::Owner->label()),
        );
});

test('organization index exposes public identifiers instead of internal keys', function () {
    $user = User::factory()->withOwnedOrganization()->create();
    $organization = $user->currentOrganization;

    $this->actingAs($user)
        ->get(route('organizations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('organizations.0.id', $organization->public_id)
            ->where('currentOrganization.id', $organization->public_id)
            ->missing('auth.user.current_organization_id'),
        );
});

test('organizations can be updated by owners', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->ownedBy($user)->create(['name' => 'Original Name']);

    $response = $this
        ->actingAs($user)
        ->patch(route('organizations.update', $organization), [
            'name' => 'Updated Name',
        ]);

    $response->assertRedirect(route('organizations.edit', $organization->fresh()));

    $this->assertDatabaseHas('organizations', [
        'id' => $organization->id,
        'name' => 'Updated Name',
    ]);

    $auditEvent = DB::table('audit_events')
        ->where('action', 'organization.updated')
        ->first();

    expect($auditEvent)->not->toBeNull()
        ->and($auditEvent->actor_user_id)->toBe($user->id)
        ->and($auditEvent->subject_id)->toBe($organization->public_id)
        ->and(json_decode($auditEvent->before, true, 512, JSON_THROW_ON_ERROR)['name'])->toBe('Original Name')
        ->and(json_decode($auditEvent->after, true, 512, JSON_THROW_ON_ERROR)['name'])->toBe('Updated Name');
});

test('organizations cannot be updated by members', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $organization = Organization::factory()->ownedBy($owner)->create();

    $organization->members()->attach($member, ['role' => OrganizationRole::Member->value]);

    $response = $this
        ->actingAs($member)
        ->patch(route('organizations.update', $organization), [
            'name' => 'Updated Name',
        ]);

    $response->assertForbidden();
});

test('numeric organization keys are not accepted by organization routes', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->ownedBy($user)->create();
    $this->actingAs($user)
        ->patch(route('organizations.update', ['organization' => $organization->id]), [
            'name' => 'Updated Name',
        ])
        ->assertNotFound();
});

test('organizations can be deleted by owners', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->ownedBy($user)->create();

    $response = $this
        ->actingAs($user)
        ->delete(route('organizations.destroy', $organization), [
            'name' => $organization->name,
        ]);

    $response->assertRedirect();

    $this->assertSoftDeleted('organizations', [
        'id' => $organization->id,
    ]);

    expect($organization->memberships()
        ->where('user_id', $user->id)
        ->where('role', OrganizationRole::Owner->value)
        ->exists())->toBeTrue();

    expect(DB::table('audit_events')
        ->where('action', 'organization.deleted')
        ->where('organization_id', $organization->id)
        ->where('actor_user_id', $user->id)
        ->where('subject_id', $organization->public_id)
        ->exists())->toBeTrue();
});

test('organization deletion requires name confirmation', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->ownedBy($user)->create();

    $response = $this
        ->actingAs($user)
        ->delete(route('organizations.destroy', $organization), [
            'name' => 'Wrong Name',
        ]);

    $response->assertSessionHasErrors('name');

    $this->assertDatabaseHas('organizations', [
        'id' => $organization->id,
        'deleted_at' => null,
    ]);
});

test('deleting current organization switches to alphabetically first remaining organization', function () {
    $user = User::factory()->create(['name' => 'Mike']);

    $zuluOrganization = Organization::factory()->ownedBy($user)->create(['name' => 'Zulu Organization']);

    $alphaOrganization = Organization::factory()->ownedBy($user)->create(['name' => 'Alpha Organization']);

    $betaOrganization = Organization::factory()->ownedBy($user)->create(['name' => 'Beta Organization']);

    $user->update(['current_organization_id' => $zuluOrganization->id]);

    $response = $this
        ->actingAs($user)
        ->delete(route('organizations.destroy', $zuluOrganization), [
            'name' => $zuluOrganization->name,
        ]);

    $response->assertRedirect();

    $this->assertSoftDeleted('organizations', [
        'id' => $zuluOrganization->id,
    ]);

    expect($user->fresh()->current_organization_id)->toEqual($alphaOrganization->id);
});

test('deleting the only current organization clears the current organization', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->ownedBy($user)->create(['name' => 'Zulu Organization']);

    $user->update(['current_organization_id' => $organization->id]);

    $response = $this
        ->actingAs($user)
        ->delete(route('organizations.destroy', $organization), [
            'name' => $organization->name,
        ]);

    $response->assertRedirect();

    $this->assertSoftDeleted('organizations', [
        'id' => $organization->id,
    ]);

    expect($user->fresh()->current_organization_id)->toBeNull();
});

test('deleting non current organization leaves current organization unchanged', function () {
    $user = User::factory()->create();
    $currentOrganization = Organization::factory()->ownedBy($user)->create(['name' => 'Current Organization']);
    $organization = Organization::factory()->ownedBy($user)->create();

    $user->update(['current_organization_id' => $currentOrganization->id]);

    $response = $this
        ->actingAs($user)
        ->delete(route('organizations.destroy', $organization), [
            'name' => $organization->name,
        ]);

    $response->assertRedirect();

    $this->assertSoftDeleted('organizations', [
        'id' => $organization->id,
    ]);

    expect($user->fresh()->current_organization_id)->toEqual($currentOrganization->id);
});

test('members can leave organizations', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $organization = Organization::factory()->ownedBy($owner)->create();

    $organization->members()->attach($member, ['role' => OrganizationRole::Member->value]);
    $membership = $organization->memberships()->where('user_id', $member->id)->firstOrFail();

    $response = $this
        ->actingAs($member)
        ->delete(route('organizations.leave', $organization));

    $response->assertRedirect(route('organizations.index'));
    $response->assertInertiaFlash('toast', ['type' => 'success', 'message' => "You left the organization \"{$organization->name}\""]);

    expect($member->fresh()->belongsToOrganization($organization))->toBeFalse();
    expect(DB::table('audit_events')
        ->where('action', 'organization.member_left')
        ->where('organization_id', $organization->id)
        ->where('actor_user_id', $member->id)
        ->where('subject_id', $membership->public_id)
        ->exists())->toBeTrue();
});

test('leaving an organization releases reserved member capacity', function () {
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

    $this
        ->actingAs($member)
        ->delete(route('organizations.leave', $organization))
        ->assertRedirect(route('organizations.index'));

    expect(DB::table('usage_counters')
        ->where('organization_id', $organization->id)
        ->where('capability_id', DB::table('capabilities')->where('code', 'max_members')->value('id'))
        ->value('quantity'))->toBe(1);
});

test('leaving current organization switches to alphabetically first remaining organization', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create(['name' => 'Mike']);

    $zuluOrganization = Organization::factory()->ownedBy($owner)->create(['name' => 'Zulu Organization']);
    $zuluOrganization->members()->attach($member, ['role' => OrganizationRole::Member->value]);

    $alphaOrganization = Organization::factory()->create(['name' => 'Alpha Organization']);
    $alphaOrganization->members()->attach($member, ['role' => OrganizationRole::Member->value]);

    $betaOrganization = Organization::factory()->create(['name' => 'Beta Organization']);
    $betaOrganization->members()->attach($member, ['role' => OrganizationRole::Member->value]);

    $member->update(['current_organization_id' => $zuluOrganization->id]);

    $response = $this
        ->actingAs($member)
        ->delete(route('organizations.leave', $zuluOrganization));

    $response->assertRedirect(route('organizations.index'));

    expect($member->fresh()->belongsToOrganization($zuluOrganization))->toBeFalse();
    expect($member->fresh()->current_organization_id)->toEqual($alphaOrganization->id);
});

test('leaving the only current organization clears the current organization', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $organization = Organization::factory()->ownedBy($owner)->create();
    $organization->members()->attach($member, ['role' => OrganizationRole::Member->value]);
    $member->update(['current_organization_id' => $organization->id]);

    $response = $this
        ->actingAs($member)
        ->delete(route('organizations.leave', $organization));

    $response->assertRedirect(route('organizations.index'));

    expect($member->fresh()->belongsToOrganization($organization))->toBeFalse()
        ->and($member->current_organization_id)->toBeNull();
});

test('organization owners cannot leave their organization', function () {
    $owner = User::factory()->create();
    $organization = Organization::factory()->ownedBy($owner)->create();

    $response = $this
        ->actingAs($owner)
        ->delete(route('organizations.leave', $organization));

    $response->assertForbidden();

    expect($owner->fresh()->belongsToOrganization($organization))->toBeTrue();
});

test('users cannot leave organizations they dont belong to', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    $response = $this
        ->actingAs($user)
        ->delete(route('organizations.leave', $organization));

    $response->assertForbidden();
});

test('deleting organization clears affected users without another organization', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();

    $organization = Organization::factory()->ownedBy($owner)->create();
    $organization->members()->attach($member, ['role' => OrganizationRole::Member->value]);

    $owner->update(['current_organization_id' => $organization->id]);
    $member->update(['current_organization_id' => $organization->id]);

    $response = $this
        ->actingAs($owner)
        ->delete(route('organizations.destroy', $organization), [
            'name' => $organization->name,
        ]);

    $response->assertRedirect();

    expect($member->fresh()->current_organization_id)->toBeNull();
});

test('owners can delete their only organization', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->ownedBy($user)->create();
    $user->switchOrganization($organization);

    $response = $this
        ->actingAs($user)
        ->delete(route('organizations.destroy', $organization), [
            'name' => $organization->name,
        ]);

    $response->assertRedirect(route('organizations.index'));

    $this->assertSoftDeleted($organization);
    expect($user->fresh()->current_organization_id)->toBeNull();
});

test('organizations cannot be deleted by non owners', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $organization = Organization::factory()->ownedBy($owner)->create();

    $organization->members()->attach($member, ['role' => OrganizationRole::Member->value]);

    $response = $this
        ->actingAs($member)
        ->delete(route('organizations.destroy', $organization), [
            'name' => $organization->name,
        ]);

    $response->assertForbidden();
});

test('users can switch organizations', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    $organization->members()->attach($user, ['role' => OrganizationRole::Member->value]);

    $response = $this
        ->actingAs($user)
        ->post(route('organizations.switch', $organization));

    $response->assertRedirect();

    expect($user->fresh()->current_organization_id)->toEqual($organization->id);
});

test('users cannot switch to organization they dont belong to', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    $response = $this
        ->actingAs($user)
        ->post(route('organizations.switch', $organization));

    $response->assertForbidden();
});

test('guests cannot access organizations', function () {
    $response = $this->get(route('organizations.index'));

    $response->assertRedirect(route('login'));
});
