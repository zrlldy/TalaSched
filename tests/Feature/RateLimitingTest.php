<?php

use App\Enums\OrganizationRole;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

test('organization invitations are rate limited per actor and organization', function (): void {
    Notification::fake();

    $owner = User::factory()->withOwnedOrganization()->create();
    $organization = $owner->currentOrganization;

    foreach (range(1, 10) as $attempt) {
        $this
            ->actingAs($owner)
            ->post(route('organizations.invitations.store', $organization), [
                'email' => "rate-limit-{$attempt}@example.com",
                'role' => OrganizationRole::Member->value,
            ])
            ->assertRedirect(route('organizations.edit', $organization));
    }

    $this
        ->actingAs($owner)
        ->post(route('organizations.invitations.store', $organization), [
            'email' => 'rate-limit-overflow@example.com',
            'role' => OrganizationRole::Member->value,
        ])
        ->assertTooManyRequests();
});

test('schedule validation is rate limited with the standard JSON error envelope', function (): void {
    $owner = User::factory()->withOwnedOrganization()->create();
    $organization = $owner->currentOrganization;

    foreach (range(1, 30) as $attempt) {
        $this
            ->actingAs($owner)
            ->postJson(route('scheduling.entries.validate', $organization), [])
            ->assertForbidden();
    }

    $response = $this
        ->actingAs($owner)
        ->postJson(route('scheduling.entries.validate', $organization), []);

    $response
        ->assertTooManyRequests()
        ->assertJsonPath('error.code', 'rate_limited')
        ->assertJsonPath('meta.correlation_id', $response->headers->get('X-Correlation-ID'));
});
