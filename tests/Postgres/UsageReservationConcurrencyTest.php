<?php

use App\Enums\CapabilityKey;
use App\Enums\SubscriptionStatus;
use App\Models\Organization;
use App\Models\User;
use App\Subscriptions\UsageService;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Throwable;

uses(TestCase::class);

/**
 * @return list<array{ok: bool, error: string|null}>
 */
function runConcurrentUsageReservationPair(Organization $organization): array
{
    $barrierPath = tempnam(sys_get_temp_dir(), 'talasched-usage-barrier-');
    $readyPaths = [];
    $resultPaths = [];
    $childPids = [];

    if ($barrierPath === false) {
        throw new RuntimeException('Unable to create the usage concurrency barrier.');
    }

    unlink($barrierPath);

    foreach ([1, 2] as $worker) {
        $readyPath = tempnam(sys_get_temp_dir(), 'talasched-usage-ready-');
        $resultPath = tempnam(sys_get_temp_dir(), 'talasched-usage-result-');

        if ($readyPath === false || $resultPath === false) {
            throw new RuntimeException('Unable to create usage concurrency worker files.');
        }

        unlink($readyPath);
        unlink($resultPath);
        $readyPaths[] = $readyPath;
        $resultPaths[] = $resultPath;

        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('Unable to fork a usage concurrency worker.');
        }

        if ($pid === 0) {
            DB::purge();
            touch($readyPath);

            while (! file_exists($barrierPath)) {
                usleep(1000);
            }

            try {
                app(UsageService::class)->reserve($organization, CapabilityKey::MaxMembers);
                file_put_contents($resultPath, json_encode(['ok' => true, 'error' => null], JSON_THROW_ON_ERROR));
            } catch (Throwable $exception) {
                file_put_contents($resultPath, json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR));
            } finally {
                DB::disconnect();
            }

            exit(0);
        }

        $childPids[] = $pid;
    }

    try {
        $deadline = microtime(true) + 10;

        while (collect($readyPaths)->filter(fn (string $path): bool => file_exists($path))->count() < count($readyPaths)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Usage concurrency workers did not reach the barrier.');
            }

            usleep(1000);
        }

        touch($barrierPath);

        foreach ($childPids as $childPid) {
            pcntl_waitpid($childPid, $status);
            expect(pcntl_wifexited($status))->toBeTrue()
                ->and(pcntl_wexitstatus($status))->toBe(0);
        }

        return array_map(
            fn (string $resultPath): array => json_decode(
                (string) file_get_contents($resultPath),
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
            $resultPaths,
        );
    } finally {
        foreach (array_merge([$barrierPath], $readyPaths, $resultPaths) as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }
}

test('concurrent capacity reservations permit exactly one final slot', function (): void {
    if (DB::getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('This integration test requires PostgreSQL and pcntl.');
    }

    $owner = User::factory()->withOwnedOrganization()->create();
    $organization = $owner->currentOrganization;
    $timestamp = now();
    $planId = DB::table('plans')->insertGetId([
        'code' => 'usage-concurrency-'.Str::uuid(),
        'name' => 'Usage concurrency',
        'is_active' => true,
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);
    DB::table('capabilities')->upsert([
        [
            'code' => CapabilityKey::MaxMembers->value,
            'name' => CapabilityKey::MaxMembers->label(),
            'value_type' => CapabilityKey::MaxMembers->valueType(),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ],
    ], ['code'], ['name', 'value_type', 'updated_at']);
    $capabilityId = DB::table('capabilities')->where('code', CapabilityKey::MaxMembers->value)->value('id');

    DB::table('plan_capability_values')->insert([
        'plan_id' => $planId,
        'capability_id' => $capabilityId,
        'integer_value' => 2,
    ]);
    app(TenantContext::class)->run($organization, function () use ($organization, $planId, $timestamp): void {
        DB::table('organization_subscriptions')->insert([
            'organization_id' => $organization->getKey(),
            'plan_id' => $planId,
            'status' => SubscriptionStatus::Active->value,
            'period_starts_at' => $timestamp->copy()->subDay(),
            'period_ends_at' => $timestamp->copy()->addMonth(),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    });

    $results = runConcurrentUsageReservationPair($organization);
    $quantity = app(UsageService::class)->current($organization, CapabilityKey::MaxMembers);

    expect(collect($results)->where('ok', true)->count())->toBe(1)
        ->and(collect($results)->where('ok', false)->count())->toBe(1)
        ->and($quantity)->toBe(2);
});
