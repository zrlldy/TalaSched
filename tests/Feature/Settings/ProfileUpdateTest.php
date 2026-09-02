<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('profile.edit'));

    $response->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->name)->toBe('Test User');
    expect($user->email)->toBe('test@example.com');
    expect($user->email_verified_at)->toBeNull();

    $auditEvent = DB::table('audit_events')
        ->where('action', 'account.profile_updated')
        ->where('actor_user_id', $user->id)
        ->first();

    expect($auditEvent)->not->toBeNull()
        ->and($auditEvent->subject_type)->toBe(User::class)
        ->and($auditEvent->subject_id)->toBe((string) $user->id)
        ->and(json_decode($auditEvent->before, associative: true, flags: JSON_THROW_ON_ERROR))
        ->toBe(['email_verified' => true])
        ->and(json_decode($auditEvent->after, associative: true, flags: JSON_THROW_ON_ERROR))
        ->toBe([
            'name_changed' => true,
            'email_changed' => true,
            'email_verified' => false,
        ]);
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => $user->email,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->delete(route('profile.destroy'), [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    $this->assertGuest();
    expect($user->fresh())->toBeNull();

    $auditEvent = DB::table('audit_events')
        ->where('action', 'account.deleted')
        ->where('actor_user_id', $user->id)
        ->first();

    expect($auditEvent)->not->toBeNull()
        ->and($auditEvent->subject_type)->toBe(User::class)
        ->and($auditEvent->subject_id)->toBe((string) $user->id)
        ->and(json_decode($auditEvent->after, associative: true, flags: JSON_THROW_ON_ERROR))
        ->toBe(['status' => 'deleted']);
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), [
            'password' => 'wrong-password',
        ]);

    $response
        ->assertSessionHasErrors('password')
        ->assertRedirect(route('profile.edit'));

    expect($user->fresh())->not->toBeNull();
});

test('organization owners must transfer ownership before deleting their account', function () {
    $user = User::factory()->withOwnedOrganization()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasErrors('account')
        ->assertRedirect(route('profile.edit'));

    $this->assertAuthenticatedAs($user);
    expect($user->fresh())->not->toBeNull();
});
