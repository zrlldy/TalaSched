<?php

use App\Enums\CapabilityKey;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

function grantManualSchedulingEntitlement(Organization $organization): void
{
    $timestamp = now();
    $planId = DB::table('plans')->insertGetId([
        'code' => 'test-manual-scheduling',
        'name' => 'Test manual scheduling',
        'is_active' => true,
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);
    $capabilityId = DB::table('capabilities')->insertGetId([
        'code' => CapabilityKey::ManualScheduling->value,
        'name' => CapabilityKey::ManualScheduling->label(),
        'value_type' => 'boolean',
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);

    DB::table('plan_capability_values')->insert([
        'plan_id' => $planId,
        'capability_id' => $capabilityId,
        'boolean_value' => true,
    ]);
    DB::table('organization_subscriptions')->insert([
        'organization_id' => $organization->id,
        'plan_id' => $planId,
        'status' => 'active',
        'period_starts_at' => $timestamp->copy()->subDay(),
        'period_ends_at' => $timestamp->copy()->addMonth(),
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);
}

function grantTimetableVersioningEntitlement(Organization $organization): void
{
    $timestamp = now();
    $planId = DB::table('plans')->insertGetId([
        'code' => 'test-timetable-versioning',
        'name' => 'Test timetable versioning',
        'is_active' => true,
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);
    $capabilityId = DB::table('capabilities')->insertGetId([
        'code' => CapabilityKey::TimetableVersioning->value,
        'name' => CapabilityKey::TimetableVersioning->label(),
        'value_type' => 'boolean',
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);

    DB::table('plan_capability_values')->insert([
        'plan_id' => $planId,
        'capability_id' => $capabilityId,
        'boolean_value' => true,
    ]);
    DB::table('organization_subscriptions')->insert([
        'organization_id' => $organization->id,
        'plan_id' => $planId,
        'status' => 'active',
        'period_starts_at' => $timestamp->copy()->subDay(),
        'period_ends_at' => $timestamp->copy()->addMonth(),
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);
}

function grantApprovalWorkflowsEntitlement(Organization $organization): void
{
    $timestamp = now();
    $planId = DB::table('plans')->insertGetId([
        'code' => 'test-approval-workflows',
        'name' => 'Test approval workflows',
        'is_active' => true,
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);
    $capabilityId = DB::table('capabilities')->insertGetId([
        'code' => CapabilityKey::ApprovalWorkflows->value,
        'name' => CapabilityKey::ApprovalWorkflows->label(),
        'value_type' => 'boolean',
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);

    DB::table('plan_capability_values')->insert([
        'plan_id' => $planId,
        'capability_id' => $capabilityId,
        'boolean_value' => true,
    ]);
    DB::table('organization_subscriptions')->insert([
        'organization_id' => $organization->id,
        'plan_id' => $planId,
        'status' => 'active',
        'period_starts_at' => $timestamp->copy()->subDay(),
        'period_ends_at' => $timestamp->copy()->addMonth(),
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);
}
