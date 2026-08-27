<?php

use App\Enums\AcademicPeriodKind;
use App\Enums\AcademicYearStatus;
use App\Enums\TimetableVersionStatus;
use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\Organization;
use App\Models\Timetable;
use App\Models\TimetableVersion;
use App\Models\User;
use App\Scheduling\PublishTimetableVersion;
use App\Subscriptions\ProvisionOrganizationSubscription;
use App\Tenancy\TenantContext;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

/**
 * @return list<array{ok: bool, error: string|null}>
 */
function runConcurrentPublicationPair(
    Organization $organization,
    User $actor,
    TimetableVersion $left,
    TimetableVersion $right,
): array {
    $barrierPath = tempnam(sys_get_temp_dir(), 'talasched-publication-barrier-');
    $readyPaths = [];
    $resultPaths = [];
    $childPids = [];

    if ($barrierPath === false) {
        throw new RuntimeException('Unable to create the publication concurrency barrier.');
    }

    unlink($barrierPath);

    foreach (['left' => $left, 'right' => $right] as $side => $version) {
        $readyPath = tempnam(sys_get_temp_dir(), 'talasched-publication-ready-');
        $resultPath = tempnam(sys_get_temp_dir(), 'talasched-publication-result-');

        if ($readyPath === false || $resultPath === false) {
            throw new RuntimeException('Unable to create publication concurrency worker files.');
        }

        unlink($readyPath);
        unlink($resultPath);
        $readyPaths[] = $readyPath;
        $resultPaths[] = $resultPath;

        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('Unable to fork a publication concurrency worker.');
        }

        if ($pid === 0) {
            DB::purge();
            $tenantContext = app(TenantContext::class);
            $tenantContext->set($organization);
            touch($readyPath);

            while (! file_exists($barrierPath)) {
                usleep(1000);
            }

            try {
                app(PublishTimetableVersion::class)->handle($version, $actor);
                file_put_contents($resultPath, json_encode([
                    'ok' => true,
                    'error' => null,
                ], JSON_THROW_ON_ERROR));
            } catch (Throwable $exception) {
                file_put_contents($resultPath, json_encode([
                    'ok' => false,
                    'error' => $exception->getMessage(),
                ], JSON_THROW_ON_ERROR));
            } finally {
                $tenantContext->clear();
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
                throw new RuntimeException('Publication concurrency workers did not reach the barrier.');
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

test('concurrent publication serializes versions and preserves one published history head', function (): void {
    if (DB::getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('This integration test requires PostgreSQL and pcntl.');
    }

    try {
        DB::connection()->getPdo();
    } catch (Throwable) {
        $this->markTestSkipped('This integration test requires a reachable PostgreSQL test database.');
    }

    $this->seed(SubscriptionCatalogSeeder::class);
    $actor = User::factory()->withOwnedOrganization()->create();
    $organization = $actor->currentOrganization;
    $tenantContext = app(TenantContext::class);

    [$left, $right] = $tenantContext->run($organization, function () use ($actor, $organization): array {
        app(ProvisionOrganizationSubscription::class)->handle($organization);

        $year = AcademicYear::factory()->forOrganization($organization)->create([
            'name' => 'Publication concurrency year',
            'starts_on' => '2026-06-01',
            'ends_on' => '2027-05-31',
            'status' => AcademicYearStatus::Active,
        ]);
        $period = AcademicPeriod::factory()->forAcademicYear($year)->create([
            'name' => 'Publication concurrency period',
            'kind' => AcademicPeriodKind::Term,
            'sequence' => 1,
            'starts_on' => '2026-06-01',
            'ends_on' => '2026-09-30',
        ]);
        $timetable = Timetable::create([
            'organization_id' => $organization->id,
            'academic_year_id' => $year->id,
            'academic_period_id' => $period->id,
            'name' => 'Publication concurrency timetable',
            'timezone' => 'Asia/Manila',
            'scheduling_granularity' => 30,
        ]);

        return [
            TimetableVersion::create([
                'organization_id' => $organization->id,
                'timetable_id' => $timetable->id,
                'version_number' => 1,
                'status' => TimetableVersionStatus::Approved,
                'created_by' => $actor->id,
            ]),
            TimetableVersion::create([
                'organization_id' => $organization->id,
                'timetable_id' => $timetable->id,
                'version_number' => 2,
                'status' => TimetableVersionStatus::Approved,
                'created_by' => $actor->id,
            ]),
        ];
    });

    $results = runConcurrentPublicationPair($organization, $actor, $left, $right);

    expect(collect($results)->where('ok', true)->count())->toBe(2)
        ->and(collect($results)->where('error', '!=', null))->toHaveCount(0);

    $tenantContext->run($organization, function () use ($left, $right): void {
        $statuses = TimetableVersion::query()
            ->whereIn('id', [$left->id, $right->id])
            ->pluck('status', 'id');

        expect($statuses->filter(fn (TimetableVersionStatus $status): bool => $status === TimetableVersionStatus::Published)->count())
            ->toBe(1)
            ->and($statuses->filter(fn (TimetableVersionStatus $status): bool => $status === TimetableVersionStatus::Superseded)->count())
            ->toBe(1);
    });
});
