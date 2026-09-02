<?php

use App\Enums\OrganizationRole;
use App\Models\User;
use App\Subscriptions\ProvisionOrganizationSubscription;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->seed(SubscriptionCatalogSeeder::class);
    $this->owner = User::factory()->withOwnedOrganization()->create();
    $this->organization = $this->owner->currentOrganization;
    app(ProvisionOrganizationSubscription::class)->handle($this->organization, $this->owner);
});

test('subscription provisioning is idempotent and records its initiating actor', function (): void {
    app(ProvisionOrganizationSubscription::class)->handle($this->organization, $this->owner);

    $auditEvents = DB::table('audit_events')
        ->where('action', 'subscription.provisioned')
        ->where('organization_id', $this->organization->id)
        ->get();

    expect($auditEvents)->toHaveCount(1)
        ->and($auditEvents->sole()->actor_user_id)->toBe($this->owner->id)
        ->and($auditEvents->sole()->subject_id)->toBe($this->organization->public_id)
        ->and(json_decode($auditEvents->sole()->after, true, 512, JSON_THROW_ON_ERROR))
        ->toBe([
            'plan_code' => 'starter',
            'status' => 'active',
        ]);
});

test('organization administrators can view the server-resolved plan, features, and limits', function (): void {
    $this->actingAs($this->owner)
        ->get(route('subscriptions.show', [
            'current_organization' => $this->organization->slug,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('subscriptions/Show')
            ->where('subscription.plan.code', 'starter')
            ->where('subscription.status', 'active')
            ->where('features.0.key', 'manual_scheduling')
            ->where('features.0.enabled', true)
            ->where('limits.0.key', 'max_members')
            ->where('limits.0.current', 1)
            ->where('canManageSubscription', true));
});

test('organization members cannot view plan and usage administration', function (): void {
    $member = User::factory()->create();
    $this->organization->members()->attach($member, ['role' => OrganizationRole::Member]);

    $this->actingAs($member)
        ->get(route('subscriptions.show', [
            'current_organization' => $this->organization->slug,
        ]))
        ->assertForbidden();
});
