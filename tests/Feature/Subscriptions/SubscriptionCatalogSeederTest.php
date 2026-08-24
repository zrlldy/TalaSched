<?php

use App\Enums\CapabilityKey;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('subscription catalog seeding is idempotent and complete', function (): void {
    $seeder = app(SubscriptionCatalogSeeder::class);

    $seeder->run();
    $seeder->run();

    expect(DB::table('plans')->whereIn('code', ['starter', 'professional', 'enterprise'])->count())->toBe(3)
        ->and(DB::table('capabilities')->whereIn('code', array_map(
            fn (CapabilityKey $capability): string => $capability->value,
            CapabilityKey::cases(),
        ))->count())->toBe(count(CapabilityKey::cases()))
        ->and(DB::table('plan_capability_values')->count())->toBe(3 * count(CapabilityKey::cases()))
        ->and(DB::table('capabilities')->where('code', CapabilityKey::ManualScheduling->value)->value('value_type'))->toBe('boolean')
        ->and(DB::table('capabilities')->where('code', CapabilityKey::MaxMembers->value)->value('value_type'))->toBe('integer');
});

test('built-in plans expose their intended capability tiers', function (): void {
    app(SubscriptionCatalogSeeder::class)->run();

    $value = function (string $planCode, CapabilityKey $capability): bool|int|null {
        $storedValue = DB::table('plan_capability_values')
            ->join('plans', 'plans.id', '=', 'plan_capability_values.plan_id')
            ->join('capabilities', 'capabilities.id', '=', 'plan_capability_values.capability_id')
            ->where('plans.code', $planCode)
            ->where('capabilities.code', $capability->value)
            ->value($capability->valueType() === 'boolean' ? 'boolean_value' : 'integer_value');

        return $capability->valueType() === 'boolean'
            ? (bool) $storedValue
            : ($storedValue === null ? null : (int) $storedValue);
    };

    expect($value('starter', CapabilityKey::CustomRoles))->toBe(false)
        ->and($value('professional', CapabilityKey::CustomRoles))->toBe(true)
        ->and($value('enterprise', CapabilityKey::MultiCampus))->toBe(true)
        ->and($value('starter', CapabilityKey::MaxMembers))->toBe(25)
        ->and($value('professional', CapabilityKey::MaxActiveTimetables))->toBe(5)
        ->and($value('enterprise', CapabilityKey::MaxActiveTimetables))->toBe(50);
});
