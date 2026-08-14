<?php

use App\Enums\CapabilityKey;
use App\Models\User;
use App\Subscriptions\EntitlementService;
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
        ->and($service->limit($this->organization, CapabilityKey::MaxMembers))->toBe(50);
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
