<?php

use App\Enums\CapabilityKey;
use App\Models\Organization;
use App\Tenancy\TenantContext;
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
    ->in('Feature', 'Browser');

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
    grantOrganizationEntitlement(
        $organization,
        'test-manual-scheduling',
        'Test manual scheduling',
        CapabilityKey::ManualScheduling,
    );
}

function grantTimetableVersioningEntitlement(Organization $organization): void
{
    grantOrganizationEntitlement(
        $organization,
        'test-timetable-versioning',
        'Test timetable versioning',
        CapabilityKey::TimetableVersioning,
    );
}

function grantApprovalWorkflowsEntitlement(Organization $organization): void
{
    grantOrganizationEntitlement(
        $organization,
        'test-approval-workflows',
        'Test approval workflows',
        CapabilityKey::ApprovalWorkflows,
    );
}

function grantCustomExcelTemplatesEntitlement(Organization $organization): void
{
    grantOrganizationEntitlement(
        $organization,
        'test-custom-excel-templates',
        'Test custom Excel templates',
        CapabilityKey::CustomExcelTemplates,
    );
}

function grantOrganizationEntitlement(
    Organization $organization,
    string $planCode,
    string $planName,
    CapabilityKey $capability,
): void {
    $timestamp = now();
    DB::table('plans')->updateOrInsert(['code' => $planCode], [
        'name' => $planName,
        'is_active' => true,
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);
    $planId = (int) DB::table('plans')->where('code', $planCode)->value('id');

    DB::table('capabilities')->updateOrInsert(['code' => $capability->value], [
        'name' => $capability->label(),
        'value_type' => 'boolean',
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);
    $capabilityId = (int) DB::table('capabilities')->where('code', $capability->value)->value('id');

    DB::table('plan_capability_values')->updateOrInsert([
        'plan_id' => $planId,
        'capability_id' => $capabilityId,
    ], [
        'boolean_value' => true,
    ]);
    app(TenantContext::class)->run($organization, fn (): bool => DB::table('organization_subscriptions')->insert([
        'organization_id' => $organization->id,
        'plan_id' => $planId,
        'status' => 'active',
        'period_starts_at' => $timestamp->copy()->subDay(),
        'period_ends_at' => $timestamp->copy()->addMonth(),
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]));
}
