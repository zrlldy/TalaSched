<?php

use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Console\Scheduling\Schedule;

test('expired invitations are deleted by the tenant-aware cleanup command', function () {
    $this->travelTo(now()->startOfDay());

    $owner = User::factory()->create();
    $organization = Organization::factory()->ownedBy($owner)->create();

    $expiredInvitation = OrganizationInvitation::factory()->expired()->create([
        'organization_id' => $organization->id,
        'invited_by' => $owner->id,
    ]);

    $unexpiredInvitation = OrganizationInvitation::factory()->expiresIn(1)->create([
        'organization_id' => $organization->id,
        'invited_by' => $owner->id,
    ]);

    $invitationWithoutExpiration = OrganizationInvitation::factory()->create([
        'organization_id' => $organization->id,
        'invited_by' => $owner->id,
    ]);

    $this->artisan('invitations:prune')->assertSuccessful();

    $this->assertDatabaseMissing('organization_invitations', [
        'id' => $expiredInvitation->id,
    ]);

    $this->assertDatabaseHas('organization_invitations', [
        'id' => $unexpiredInvitation->id,
    ]);

    $this->assertDatabaseHas('organization_invitations', [
        'id' => $invitationWithoutExpiration->id,
    ]);

    expect(app(TenantContext::class)->organization())->toBeNull();
});

test('tenant cleanup commands are scheduled daily', function () {
    $scheduledCommands = collect(app(Schedule::class)->events())
        ->pluck('command')
        ->filter()
        ->implode("\n");

    expect($scheduledCommands)
        ->toContain('invitations:prune')
        ->toContain('idempotency:prune');
});
