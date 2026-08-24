<?php

use App\Enums\CapabilityKey;
use App\Enums\SubscriptionStatus;
use App\Models\User;
use App\Subscriptions\CapabilityGuard;
use App\Subscriptions\EntitlementService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->organization = User::factory()->withOwnedOrganization()->create()->currentOrganization;
    $this->planId = DB::table('plans')->insertGetId([
        'code' => 'professional',
        'name' => 'Professional',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $this->manualCapabilityId = DB::table('capabilities')->insertGetId([
        'code' => CapabilityKey::ManualScheduling->value,
        'name' => 'Manual scheduling',
        'value_type' => 'boolean',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $this->memberLimitId = DB::table('capabilities')->insertGetId([
        'code' => CapabilityKey::MaxMembers->value,
        'name' => 'Maximum members',
        'value_type' => 'integer',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('plan_capability_values')->insert([
        'plan_id' => $this->planId,
        'capability_id' => $this->manualCapabilityId,
        'boolean_value' => true,
    ]);
    DB::table('plan_capability_values')->insert([
        'plan_id' => $this->planId,
        'capability_id' => $this->memberLimitId,
        'integer_value' => 50,
    ]);
    DB::table('organization_subscriptions')->insert([
        'organization_id' => $this->organization->id,
        'plan_id' => $this->planId,
        'status' => 'active',
        'period_starts_at' => now()->subDay(),
        'period_ends_at' => now()->addMonth(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

test('capabilities and limits are resolved without checking plan names', function () {
    $service = app(EntitlementService::class);

    expect($service->allows($this->organization, CapabilityKey::ManualScheduling))->toBeTrue()
        ->and($service->limit($this->organization, CapabilityKey::MaxMembers))->toBe(50)
        ->and($service->values($this->organization))->toMatchArray([
            CapabilityKey::ManualScheduling->value => true,
            CapabilityKey::MaxMembers->value => 50,
        ]);
});

test('capability guard centralizes feature and capacity decisions', function () {
    $guard = app(CapabilityGuard::class);

    $guard->assertEnabled($this->organization, CapabilityKey::ManualScheduling);
    $guard->assertCanConsume($this->organization, CapabilityKey::MaxMembers, 49);

    expect($guard->hasCapacity($this->organization, CapabilityKey::MaxMembers, 50))->toBeFalse()
        ->and(fn () => $guard->assertCanConsume($this->organization, CapabilityKey::MaxMembers, 50))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => $guard->assertCanConsume($this->organization, CapabilityKey::MaxMembers, -1))
        ->toThrow(InvalidArgumentException::class);
});

test('organization overrides take precedence over plan values', function () {
    DB::table('organization_entitlement_overrides')->insert([
        'organization_id' => $this->organization->id,
        'capability_id' => $this->memberLimitId,
        'integer_value' => 125,
        'reason' => 'Contracted enterprise allowance',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(app(EntitlementService::class)->limit($this->organization, CapabilityKey::MaxMembers))->toBe(125);
});

test('expired subscriptions grant no entitlement', function () {
    DB::table('organization_subscriptions')->where('organization_id', $this->organization->id)->update([
        'period_ends_at' => now()->subMinute(),
    ]);

    expect(app(EntitlementService::class)->allows($this->organization, CapabilityKey::ManualScheduling))->toBeFalse();
});

test('subscription lifecycle statuses have explicit entitlement semantics', function () {
    $service = app(EntitlementService::class);
    $cases = [
        [SubscriptionStatus::Trialing, true, now()->addDay(), now()->addDay()],
        [SubscriptionStatus::Active, true, null, now()->addDay()],
        [SubscriptionStatus::GracePeriod, true, now()->addDay(), now()->subDay()],
        [SubscriptionStatus::PastDue, false, now()->subDay(), now()->subDay()],
        [SubscriptionStatus::Canceled, true, null, now()->addDay()],
        [SubscriptionStatus::Expired, false, null, now()->subDay()],
    ];

    foreach ($cases as [$status, $expectedAccess, $graceEndsAt, $periodEndsAt]) {
        DB::table('organization_subscriptions')->where('organization_id', $this->organization->id)->update([
            'status' => $status->value,
            'trial_ends_at' => $status === SubscriptionStatus::Trialing ? now()->addDay() : null,
            'period_ends_at' => $periodEndsAt,
            'grace_ends_at' => $graceEndsAt,
        ]);

        expect($service->status($this->organization))->toBe($status)
            ->and($service->allows($this->organization, CapabilityKey::ManualScheduling))->toBe($expectedAccess);
    }
});
