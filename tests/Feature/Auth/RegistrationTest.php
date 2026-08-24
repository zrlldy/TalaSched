<?php

use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('registration screen includes organization invitation context', function () {
    $owner = User::factory()->create();
    $organization = Organization::factory()->ownedBy($owner)->create(['name' => 'Laravel Organization']);
    $invitation = OrganizationInvitation::factory()->create([
        'organization_id' => $organization->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this->get(route('register', ['invitation' => $invitation->plainTextToken()]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('auth/Register')
        ->where('organizationInvitation.token', $invitation->plainTextToken())
        ->where('organizationInvitation.organizationName', 'Laravel Organization'),
    );
});

test('new users can register', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();

    $user = User::where('email', 'test@example.com')->firstOrFail();

    $response->assertRedirect(route('organizations.index'));

    expect($user->organizations()->exists())->toBeFalse()
        ->and($user->current_organization_id)->toBeNull();
});
