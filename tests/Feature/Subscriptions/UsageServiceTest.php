<?php

use App\Enums\CapabilityKey;
use App\Models\User;
use App\Subscriptions\UsageService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->organization = User::factory()->withOwnedOrganization()->create()->currentOrganization;
    $timestamp = now();
    $this->planId = DB::table('plans')->insertGetId([
        'code' => 'usage-test-plan',
        'name' => 'Usage test plan',
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);
    $memberCapabilityId = DB::table('capabilities')->insertGetId([
        'code' => CapabilityKey::MaxMembers->value,
        'name' => CapabilityKey::MaxMembers->label(),
        'value_type' => 'integer',
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);
    $timetableCapabilityId = DB::table('capabilities')->insertGetId([
        'code' => CapabilityKey::MaxActiveTimetables->value,
        'name' => CapabilityKey::MaxActiveTimetables->label(),
        'value_type' => 'integer',
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);
    DB::table('plan_capability_values')->insert([
        'plan_id' => $this->planId,
        'capability_id' => $memberCapabilityId,
        'integer_value' => 3,
    ]);
    DB::table('plan_capability_values')->insert([
        'plan_id' => $this->planId,
        'capability_id' => $timetableCapabilityId,
        'integer_value' => 2,
    ]);
    DB::table('organization_subscriptions')->insert([
        'organization_id' => $this->organization->id,
        'plan_id' => $this->planId,
        'status' => 'active',
        'period_starts_at' => $timestamp->copy()->subDay(),
        'period_ends_at' => $timestamp->copy()->addMonth(),
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);
});

test('usage reservations initialize existing counts and update atomically', function (): void {
    $service = app(UsageService::class);

    expect($service->current($this->organization, CapabilityKey::MaxMembers))->toBe(1)
        ->and($service->reserve($this->organization, CapabilityKey::MaxMembers, 2))->toBe(3)
        ->and($service->current($this->organization, CapabilityKey::MaxMembers))->toBe(3)
        ->and($service->release($this->organization, CapabilityKey::MaxMembers))->toBe(2)
        ->and($service->reserve($this->organization, CapabilityKey::MaxActiveTimetables))->toBe(1)
        ->and(DB::table('usage_counters')->count())->toBe(2);
});

test('usage reservations reject over-limit consumption without changing the counter', function (): void {
    $service = app(UsageService::class);
    $service->reserve($this->organization, CapabilityKey::MaxMembers, 2);

    expect(fn () => $service->reserve($this->organization, CapabilityKey::MaxMembers))
        ->toThrow(AuthorizationException::class)
        ->and($service->current($this->organization, CapabilityKey::MaxMembers))->toBe(3);
});

test('usage above a downgraded limit remains visible but prevents further consumption', function (): void {
    $service = app(UsageService::class);
    $service->reserve($this->organization, CapabilityKey::MaxMembers, 2);

    DB::table('plan_capability_values')
        ->where('plan_id', $this->planId)
        ->where('capability_id', DB::table('capabilities')->where('code', CapabilityKey::MaxMembers->value)->value('id'))
        ->update(['integer_value' => 2]);

    expect($service->current($this->organization, CapabilityKey::MaxMembers))->toBe(3)
        ->and(fn () => $service->reserve($this->organization, CapabilityKey::MaxMembers))
        ->toThrow(AuthorizationException::class)
        ->and($service->release($this->organization, CapabilityKey::MaxMembers))->toBe(2)
        ->and(fn () => $service->reserve($this->organization, CapabilityKey::MaxMembers))
        ->toThrow(AuthorizationException::class);
});

test('usage reservations roll back with the surrounding transaction', function (): void {
    $service = app(UsageService::class);

    expect(fn () => DB::transaction(function () use ($service): void {
        $service->reserve($this->organization, CapabilityKey::MaxMembers);

        throw new RuntimeException('force rollback');
    }))->toThrow(RuntimeException::class, 'force rollback');

    expect($service->current($this->organization, CapabilityKey::MaxMembers))->toBe(1)
        ->and(DB::table('usage_counters')->count())->toBe(0);
});

test('usage releases cannot underflow and invalid quantities are rejected', function (): void {
    $service = app(UsageService::class);

    expect(fn () => $service->release($this->organization, CapabilityKey::MaxMembers, 2))
        ->toThrow(DomainException::class)
        ->and(fn () => $service->reserve($this->organization, CapabilityKey::MaxMembers, 0))
        ->toThrow(InvalidArgumentException::class);
});
