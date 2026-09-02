<?php

use App\Enums\OrganizationPermission;
use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

test('entitled administrators can create and update custom roles', function (): void {
    $owner = User::factory()->withOwnedOrganization()->create();
    $organization = $owner->currentOrganization;
    seedRolePlanForOrganization($organization, 'professional');

    $response = $this
        ->actingAs($owner)
        ->post(route('organizations.roles.store', $organization), [
            'name' => 'Department coordinator',
            'permissions' => [
                OrganizationPermission::ManageAcademic->value,
                OrganizationPermission::ManageScheduling->value,
            ],
        ]);

    $response->assertRedirect(route('organizations.edit', $organization));

    $role = Role::query()
        ->where('organization_id', $organization->id)
        ->where('code', 'department-coordinator')
        ->firstOrFail();

    expect($role->is_system)->toBeFalse()
        ->and($role->permissions()->pluck('code')->sort()->values()->all())
        ->toEqual([
            OrganizationPermission::ManageAcademic->value,
            OrganizationPermission::ManageScheduling->value,
        ]);
    expect(DB::table('audit_events')
        ->where('action', 'organization.role_created')
        ->where('organization_id', $organization->id)
        ->where('actor_user_id', $owner->id)
        ->where('subject_id', $role->code)
        ->exists())->toBeTrue();

    $response = $this
        ->actingAs($owner)
        ->patch(route('organizations.roles.update', [$organization, $role]), [
            'name' => 'Academic coordinator',
            'permissions' => [OrganizationPermission::ManageAcademic->value],
        ]);

    $response->assertRedirect(route('organizations.edit', $organization));

    expect($role->fresh()->name)->toBe('Academic coordinator')
        ->and($role->fresh()->permissions()->pluck('code')->all())
        ->toEqual([OrganizationPermission::ManageAcademic->value]);
    expect(DB::table('audit_events')
        ->where('action', 'organization.role_updated')
        ->where('organization_id', $organization->id)
        ->where('actor_user_id', $owner->id)
        ->where('subject_id', $role->code)
        ->exists())->toBeTrue();

    $this
        ->actingAs($owner)
        ->delete(route('organizations.roles.destroy', [$organization, $role]))
        ->assertRedirect(route('organizations.edit', $organization));

    expect(DB::table('audit_events')
        ->where('action', 'organization.role_deleted')
        ->where('organization_id', $organization->id)
        ->where('actor_user_id', $owner->id)
        ->where('subject_id', $role->code)
        ->exists())->toBeTrue();
});

test('custom role endpoints require both the capability and organization permission', function (): void {
    $owner = User::factory()->withOwnedOrganization()->create();
    $organization = $owner->currentOrganization;

    $this
        ->actingAs($owner)
        ->post(route('organizations.roles.store', $organization), [
            'name' => 'Unavailable role',
            'permissions' => [],
        ])
        ->assertForbidden();

    seedRolePlanForOrganization($organization, 'professional');

    $member = User::factory()->create();
    $organization->members()->attach($member, ['role' => OrganizationRole::Member]);

    $this
        ->actingAs($member)
        ->post(route('organizations.roles.store', $organization), [
            'name' => 'Member role',
            'permissions' => [],
        ])
        ->assertForbidden();
});

test('assigned custom roles cannot be deleted and system roles cannot be updated', function (): void {
    $owner = User::factory()->withOwnedOrganization()->create();
    $member = User::factory()->create();
    $organization = $owner->currentOrganization;
    $organization->members()->attach($member, ['role' => OrganizationRole::Member]);
    seedRolePlanForOrganization($organization, 'professional');

    $customRole = $organization->roles()->create([
        'code' => 'assigned-role',
        'name' => 'Assigned role',
        'is_system' => false,
    ]);
    $membership = $organization->memberships()->where('user_id', $member->id)->firstOrFail();
    DB::table('membership_role_assignments')->insert([
        'organization_id' => $organization->id,
        'membership_id' => $membership->id,
        'role_id' => $customRole->id,
        'academic_unit_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this
        ->from(route('organizations.edit', $organization))
        ->actingAs($owner)
        ->delete(route('organizations.roles.destroy', [$organization, $customRole]))
        ->assertRedirect(route('organizations.edit', $organization))
        ->assertSessionHasErrors('role');

    $this
        ->actingAs($owner)
        ->patch(route('organizations.roles.update', [
            $organization,
            $organization->roles()->where('code', OrganizationRole::Admin->value)->firstOrFail(),
        ]), [
            'name' => 'Cannot change administrator',
            'permissions' => [],
        ])
        ->assertForbidden();

    expect(Role::query()->whereKey($customRole->id)->exists())->toBeTrue();
});

test('organization settings expose role definitions and capability feedback', function (): void {
    $owner = User::factory()->withOwnedOrganization()->create();
    $organization = $owner->currentOrganization;

    $this
        ->actingAs($owner)
        ->get(route('organizations.edit', $organization))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('organizations/Edit')
            ->has('roles', 3)
            ->has('availablePermissions', count(OrganizationPermission::cases()))
            ->where('permissions.customRolesEnabled', false)
            ->where('permissions.canManageCustomRoles', false));

    seedRolePlanForOrganization($organization, 'professional');

    $this
        ->actingAs($owner)
        ->get(route('organizations.edit', $organization))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('permissions.customRolesEnabled', true)
            ->where('permissions.canManageCustomRoles', true));
});

function seedRolePlanForOrganization(Organization $organization, string $planCode): void
{
    app(SubscriptionCatalogSeeder::class)->run();

    $planId = DB::table('plans')->where('code', $planCode)->value('id');

    DB::table('organization_subscriptions')->insert([
        'organization_id' => $organization->id,
        'plan_id' => $planId,
        'status' => 'active',
        'period_starts_at' => now()->subDay(),
        'period_ends_at' => now()->addMonth(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}
