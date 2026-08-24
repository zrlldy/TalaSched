<?php

use App\Enums\OrganizationRole;
use App\Enums\ScheduleResourceRole;
use App\Models\OfferingComponent;
use App\Models\Organization;
use App\Models\SchedulingResource;
use App\Models\TimetableVersion;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Str;

test('tenant-prefixed requests reject users outside the organization', function () {
    $user = User::factory()->withOwnedOrganization()->create();
    $foreignOrganization = Organization::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard', ['current_organization' => $foreignOrganization->slug]))
        ->assertForbidden();

    expect(app(TenantContext::class)->organization())->toBeNull();
});

test('nested membership binding rejects a membership from another organization', function () {
    $owner = User::factory()->create();
    $foreignMember = User::factory()->create();
    $organization = Organization::factory()->ownedBy($owner)->create();
    $foreignOrganization = Organization::factory()->create();

    $foreignOrganization->members()->attach($foreignMember, ['role' => OrganizationRole::Member->value]);
    $foreignMembership = $foreignOrganization->memberships()
        ->where('user_id', $foreignMember->id)
        ->firstOrFail();

    $this->actingAs($owner)
        ->delete(route('organizations.members.destroy', [
            'organization' => $organization,
            'membership' => $foreignMembership,
        ]))
        ->assertNotFound();

    expect($foreignMember->fresh()->belongsToOrganization($foreignOrganization))->toBeTrue();
});

test('scheduling commands reject foreign public identifiers at the direct query boundary', function () {
    $user = User::factory()->withOwnedOrganization()->create();
    $organization = $user->currentOrganization;
    grantManualSchedulingEntitlement($organization);
    $foreignVersion = TimetableVersion::factory()->create();
    $foreignComponent = OfferingComponent::factory()->create();
    $foreignResources = SchedulingResource::factory()->count(2)->create();

    $this->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
        ->actingAs($user)
        ->postJson(route('scheduling.entries.store', $organization), [
            'timetable_version_id' => $foreignVersion->public_id,
            'offering_component_id' => $foreignComponent->public_id,
            'weekday' => 1,
            'starts_at_minute' => 480,
            'ends_at_minute' => 570,
            'resources' => [
                ['resource_id' => $foreignResources[0]->public_id, 'role' => ScheduleResourceRole::Room->value],
                ['resource_id' => $foreignResources[1]->public_id, 'role' => ScheduleResourceRole::Equipment->value],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonFragment(['field' => 'timetable_version_id'])
        ->assertJsonFragment(['field' => 'offering_component_id'])
        ->assertJsonFragment(['field' => 'resources.0.resource_id']);
});
