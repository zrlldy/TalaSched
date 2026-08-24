<?php

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

test('custom role administration requires the entitlement', function (): void {
    $owner = User::factory()->withOwnedOrganization()->create();
    $organization = $owner->currentOrganization;
    $customRole = $organization->roles()->create([
        'code' => 'custom-registrar',
        'name' => 'Custom registrar',
        'is_system' => false,
    ]);

    $this->seed(SubscriptionCatalogSeeder::class);

    expect(Gate::forUser($owner)->allows('viewAny', [Role::class, $organization]))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('view', $customRole))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('create', [Role::class, $organization]))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('update', $customRole))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('delete', $customRole))->toBeFalse();
});

test('an entitled owner can administer custom roles but not system roles', function (): void {
    $owner = User::factory()->withOwnedOrganization()->create();
    $organization = $owner->currentOrganization;
    $this->seed(SubscriptionCatalogSeeder::class);
    createSubscription($organization, 'professional');

    $customRole = $organization->roles()->create([
        'code' => 'custom-registrar',
        'name' => 'Custom registrar',
        'is_system' => false,
    ]);
    $systemRole = $organization->roles()->where('code', OrganizationRole::Admin->value)->firstOrFail();

    expect(Gate::forUser($owner)->allows('create', [Role::class, $organization]))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('update', $customRole))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('delete', $customRole))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('update', $systemRole))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('delete', $systemRole))->toBeFalse();
});

test('members cannot administer custom roles even with an entitled organization', function (): void {
    $owner = User::factory()->withOwnedOrganization()->create();
    $member = User::factory()->create();
    $organization = $owner->currentOrganization;
    $organization->members()->attach($member, ['role' => OrganizationRole::Member]);
    $this->seed(SubscriptionCatalogSeeder::class);
    createSubscription($organization, 'professional');

    $customRole = $organization->roles()->create([
        'code' => 'custom-registrar',
        'name' => 'Custom registrar',
        'is_system' => false,
    ]);

    expect(Gate::forUser($member)->allows('view', $customRole))->toBeTrue()
        ->and(Gate::forUser($member)->allows('create', [Role::class, $organization]))->toBeFalse()
        ->and(Gate::forUser($member)->allows('update', $customRole))->toBeFalse()
        ->and(Gate::forUser($member)->allows('delete', $customRole))->toBeFalse();
});

function createSubscription(Organization $organization, string $planCode): void
{
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
