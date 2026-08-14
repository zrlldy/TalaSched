<?php

use App\Enums\OrganizationRole;
use App\Jobs\Middleware\UseTenantContext;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Notifications\Organizations\OrganizationInvitation as OrganizationInvitationNotification;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

test('organization invitations can be created', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $organization = Organization::factory()->create();

    $organization->members()->attach($owner, ['role' => OrganizationRole::Owner->value]);

    $response = $this
        ->actingAs($owner)
        ->post(route('organizations.invitations.store', $organization), [
            'email' => 'invited@example.com',
            'role' => OrganizationRole::Member->value,
        ]);

    $response->assertRedirect(route('organizations.edit', $organization));

    $this->assertDatabaseHas('organization_invitations', [
        'organization_id' => $organization->id,
        'email' => 'invited@example.com',
        'role' => OrganizationRole::Member->value,
    ]);
});

test('invitation email for existing users uses login route', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $organization = Organization::factory()->create();

    $organization->members()->attach($owner, ['role' => OrganizationRole::Owner->value]);

    $invitation = OrganizationInvitation::factory()->create([
        'organization_id' => $organization->id,
        'email' => $invitedUser->email,
        'invited_by' => $owner->id,
    ]);

    $mail = (new OrganizationInvitationNotification($invitation))->toMail($invitedUser);

    expect($mail->actionUrl)->toBe(route('login', ['invitation' => $invitation->code]));
    $this->assertStringContainsString('dashboard', implode(' ', $mail->introLines));
});

test('invitation email for unknown users uses login route', function () {
    $owner = User::factory()->create();
    $organization = Organization::factory()->create();

    $organization->members()->attach($owner, ['role' => OrganizationRole::Owner->value]);

    $invitation = OrganizationInvitation::factory()->create([
        'organization_id' => $organization->id,
        'email' => 'unknown@example.com',
        'invited_by' => $owner->id,
    ]);

    $mail = (new OrganizationInvitationNotification($invitation))->toMail((object) []);

    expect($mail->actionUrl)->toBe(route('login', ['invitation' => $invitation->code]));
    $this->assertStringContainsString('log in', strtolower(implode(' ', $mail->introLines)));
});

test('queued invitation notifications carry public tenant context middleware', function () {
    $invitation = OrganizationInvitation::factory()->create();
    $notification = new OrganizationInvitationNotification($invitation);
    $middleware = $notification->middleware((object) [], 'mail');

    expect($notification->organizationPublicId)->toBe($invitation->organization->public_id)
        ->and($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(UseTenantContext::class)
        ->and($middleware[0]->organizationPublicId)->toBe($invitation->organization->public_id);
});

test('organization invitations can be created by admins', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $organization = Organization::factory()->create();

    $organization->members()->attach($owner, ['role' => OrganizationRole::Owner->value]);
    $organization->members()->attach($admin, ['role' => OrganizationRole::Admin->value]);

    $response = $this
        ->actingAs($admin)
        ->post(route('organizations.invitations.store', $organization), [
            'email' => 'invited@example.com',
            'role' => OrganizationRole::Member->value,
        ]);

    $response->assertRedirect(route('organizations.edit', $organization));
});

test('existing organization members cannot be invited', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $member = User::factory()->create(['email' => 'member@example.com']);
    $organization = Organization::factory()->create();

    $organization->members()->attach($owner, ['role' => OrganizationRole::Owner->value]);
    $organization->members()->attach($member, ['role' => OrganizationRole::Member->value]);

    $response = $this
        ->actingAs($owner)
        ->post(route('organizations.invitations.store', $organization), [
            'email' => 'member@example.com',
            'role' => OrganizationRole::Member->value,
        ]);

    $response->assertSessionHasErrors('email');
});

test('duplicate invitations cannot be created', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $organization = Organization::factory()->create();
    $organization->members()->attach($owner, ['role' => OrganizationRole::Owner->value]);

    OrganizationInvitation::factory()->create([
        'organization_id' => $organization->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($owner)
        ->post(route('organizations.invitations.store', $organization), [
            'email' => 'invited@example.com',
            'role' => OrganizationRole::Member->value,
        ]);

    $response->assertSessionHasErrors('email');
});

test('organization invitations cannot be created by members', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $organization = Organization::factory()->create();

    $organization->members()->attach($owner, ['role' => OrganizationRole::Owner->value]);
    $organization->members()->attach($member, ['role' => OrganizationRole::Member->value]);

    $response = $this
        ->actingAs($member)
        ->post(route('organizations.invitations.store', $organization), [
            'email' => 'invited@example.com',
            'role' => OrganizationRole::Member->value,
        ]);

    $response->assertForbidden();
});

test('organization invitations can be cancelled by owners', function () {
    $owner = User::factory()->create();
    $organization = Organization::factory()->create();

    $organization->members()->attach($owner, ['role' => OrganizationRole::Owner->value]);

    $invitation = OrganizationInvitation::factory()->create([
        'organization_id' => $organization->id,
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($owner)
        ->delete(route('organizations.invitations.destroy', [$organization, $invitation]));

    $response->assertRedirect(route('organizations.edit', $organization));

    $this->assertDatabaseMissing('organization_invitations', [
        'id' => $invitation->id,
    ]);
});

test('organization invitations can be accepted', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $organization = Organization::factory()->create();

    $organization->members()->attach($owner, ['role' => OrganizationRole::Owner->value]);

    $invitation = OrganizationInvitation::factory()->create([
        'organization_id' => $organization->id,
        'email' => 'invited@example.com',
        'role' => OrganizationRole::Member,
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->post(route('invitations.accept', $invitation));

    $response->assertRedirect(route('dashboard'));
    $response->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Invitation accepted.']);

    expect($invitedUser->fresh()->belongsToOrganization($organization))->toBeTrue();
    expect($invitation->fresh()->accepted_at)->not->toBeNull();
});

test('unaffiliated users can review pending invitations on the organizations page', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $organization = Organization::factory()->create(['name' => 'Inviting Organization']);

    $organization->members()->attach($owner, ['role' => OrganizationRole::Owner->value]);

    $invitation = OrganizationInvitation::factory()->create([
        'organization_id' => $organization->id,
        'email' => 'INVITED@example.com',
        'invited_by' => $owner->id,
    ]);

    $this->actingAs($invitedUser)
        ->get(route('organizations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organizations/Index')
            ->has('organizations', 0)
            ->has('pendingInvitations', 1)
            ->where('pendingInvitations.0.code', $invitation->code)
            ->where('pendingInvitations.0.organization.name', 'Inviting Organization'),
        );
});

test('organization invitations can be declined by the invited user', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $organization = Organization::factory()->create();

    $organization->members()->attach($owner, ['role' => OrganizationRole::Owner->value]);

    $invitation = OrganizationInvitation::factory()->create([
        'organization_id' => $organization->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->delete(route('invitations.decline', $invitation));

    $response->assertRedirect(route('organizations.index'));

    $this->assertDatabaseMissing('organization_invitations', [
        'id' => $invitation->id,
    ]);
});

test('organization invitations cannot be declined by uninvited user', function () {
    $owner = User::factory()->create();
    $uninvitedUser = User::factory()->create(['email' => 'uninvited@example.com']);
    $organization = Organization::factory()->create();

    $organization->members()->attach($owner, ['role' => OrganizationRole::Owner->value]);

    $invitation = OrganizationInvitation::factory()->create([
        'organization_id' => $organization->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($uninvitedUser)
        ->delete(route('invitations.decline', $invitation));

    $response->assertSessionHasErrors('invitation');

    $this->assertDatabaseHas('organization_invitations', [
        'id' => $invitation->id,
    ]);
});

test('accepted organization invitations cannot be declined', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $organization = Organization::factory()->create();

    $organization->members()->attach($owner, ['role' => OrganizationRole::Owner->value]);

    $invitation = OrganizationInvitation::factory()->accepted()->create([
        'organization_id' => $organization->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->delete(route('invitations.decline', $invitation));

    $response->assertSessionHasErrors('invitation');

    $this->assertDatabaseHas('organization_invitations', [
        'id' => $invitation->id,
    ]);
});

test('organization invitations cannot be accepted by uninvited user', function () {
    $owner = User::factory()->create();
    $uninvitedUser = User::factory()->create(['email' => 'uninvited@example.com']);
    $organization = Organization::factory()->create();

    $organization->members()->attach($owner, ['role' => OrganizationRole::Owner->value]);

    $invitation = OrganizationInvitation::factory()->create([
        'organization_id' => $organization->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($uninvitedUser)
        ->post(route('invitations.accept', $invitation));

    $response->assertSessionHasErrors('invitation');

    expect($uninvitedUser->fresh()->belongsToOrganization($organization))->toBeFalse();
});

test('expired invitations cannot be accepted', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $organization = Organization::factory()->create();

    $organization->members()->attach($owner, ['role' => OrganizationRole::Owner->value]);

    $invitation = OrganizationInvitation::factory()->expired()->create([
        'organization_id' => $organization->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->post(route('invitations.accept', $invitation));

    $response->assertSessionHasErrors('invitation');

    expect($invitedUser->fresh()->belongsToOrganization($organization))->toBeFalse();
});
