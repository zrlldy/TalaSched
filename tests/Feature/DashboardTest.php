<?php

use App\Actions\Organizations\GetPendingOrganizationInvitations;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $user = User::factory()->withOwnedOrganization()->create();
    $organization = $user->currentOrganization;

    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->withOwnedOrganization()->create();
    $organization = $user->currentOrganization;

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard'));

    $response->assertOk();
});

test('dashboard shares the current organization entitlement map', function () {
    $user = User::factory()->withOwnedOrganization()->create();
    grantManualSchedulingEntitlement($user->currentOrganization);

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('entitlements.manual_scheduling', true)
        ->where('entitlements.max_members', null),
    );
});

test('dashboard includes pending invitations for the authenticated user', function () {
    $owner = User::factory()->create(['name' => 'Taylor Otwell']);
    $invitedUser = User::factory()->withOwnedOrganization()->create(['email' => 'invited@example.com']);
    $organization = Organization::factory()->ownedBy($owner)->create(['name' => 'Laravel Organization']);

    $invitation = OrganizationInvitation::factory()->create([
        'organization_id' => $organization->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->has('pendingInvitations', 1)
        ->where('pendingInvitations.0.id', $invitation->public_id)
        ->where('pendingInvitations.0.inviterName', 'Taylor Otwell')
        ->where('pendingInvitations.0.organization.name', 'Laravel Organization')
        ->where('pendingInvitations.0.organization.slug', $organization->slug)
        ->missing('pendingInvitations.0.organizationName'),
    );
});

test('dashboard invitation projections use a bounded query count as invitations grow', function (): void {
    $inviter = User::factory()->create();
    $invitedUser = User::factory()->withOwnedOrganization()->create();
    $organization = Organization::factory()->ownedBy($inviter)->create();
    $action = app(GetPendingOrganizationInvitations::class);

    OrganizationInvitation::factory()->create([
        'organization_id' => $organization->id,
        'email' => $invitedUser->email,
        'invited_by' => $inviter->id,
    ]);

    DB::enableQueryLog();

    try {
        DB::flushQueryLog();
        $singleInvitation = $action->handle($invitedUser);
        $singleInvitationQueryCount = count(DB::getQueryLog());
    } finally {
        DB::disableQueryLog();
    }

    OrganizationInvitation::factory()->count(5)->create([
        'organization_id' => $organization->id,
        'email' => $invitedUser->email,
        'invited_by' => $inviter->id,
    ]);

    DB::enableQueryLog();

    try {
        DB::flushQueryLog();
        $multipleInvitations = $action->handle($invitedUser);
        $multipleInvitationQueryCount = count(DB::getQueryLog());
    } finally {
        DB::disableQueryLog();
    }

    expect($singleInvitation)->toHaveCount(1)
        ->and($multipleInvitations)->toHaveCount(6)
        ->and($singleInvitationQueryCount)->toBe(3)
        ->and($multipleInvitationQueryCount)->toBe($singleInvitationQueryCount);
});

test('dashboard does not include accepted invitations', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->withOwnedOrganization()->create(['email' => 'invited@example.com']);
    $organization = Organization::factory()->ownedBy($owner)->create();

    OrganizationInvitation::factory()->accepted()->create([
        'organization_id' => $organization->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->has('pendingInvitations', 0),
    );
});

test('dashboard excludes expired invitations without deleting them', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->withOwnedOrganization()->create(['email' => 'invited@example.com']);
    $organization = Organization::factory()->ownedBy($owner)->create();

    $invitation = OrganizationInvitation::factory()->expired()->create([
        'organization_id' => $organization->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->has('pendingInvitations', 0),
    );

    $this->assertDatabaseHas('organization_invitations', [
        'id' => $invitation->id,
    ]);
});

test('dashboard does not include or delete other users invitations', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->withOwnedOrganization()->create(['email' => 'invited@example.com']);
    $organization = Organization::factory()->ownedBy($owner)->create();

    $invitation = OrganizationInvitation::factory()->expired()->create([
        'organization_id' => $organization->id,
        'email' => 'someone@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->has('pendingInvitations', 0),
    );

    $this->assertDatabaseHas('organization_invitations', [
        'id' => $invitation->id,
    ]);
});
