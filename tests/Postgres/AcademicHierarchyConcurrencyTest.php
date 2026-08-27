<?php

use App\Academic\AcademicHierarchyService;
use App\Models\AcademicUnitType;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Throwable;

uses(TestCase::class);

test('concurrent child creation preserves hierarchy closure rows', function (): void {
    if (DB::getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('This integration test requires PostgreSQL and pcntl.');
    }

    $organization = Organization::factory()->create();
    $tenantContext = app(TenantContext::class);
    [$rootType, $childType, $root] = $tenantContext->run($organization, function () use ($organization): array {
        $rootType = AcademicUnitType::factory()->forOrganization($organization)->create();
        $childType = AcademicUnitType::factory()->forOrganization($organization)->create();

        DB::table('academic_unit_type_edges')->insert([
            'organization_id' => $organization->getKey(),
            'parent_type_id' => $rootType->getKey(),
            'child_type_id' => $childType->getKey(),
        ]);

        $root = app(AcademicHierarchyService::class)->create($organization, $rootType, 'Concurrent root');

        return [$rootType, $childType, $root];
    });

    $barrierPath = tempnam(sys_get_temp_dir(), 'talasched-hierarchy-barrier-');
    $readyPaths = [];
    $resultPaths = [];
    $childPids = [];

    if ($barrierPath === false) {
        throw new RuntimeException('Unable to create the concurrency barrier file.');
    }

    unlink($barrierPath);

    foreach ([1, 2] as $worker) {
        $readyPath = tempnam(sys_get_temp_dir(), 'talasched-hierarchy-ready-');
        $resultPath = tempnam(sys_get_temp_dir(), 'talasched-hierarchy-result-');

        if ($readyPath === false || $resultPath === false) {
            throw new RuntimeException('Unable to create concurrency worker files.');
        }

        unlink($readyPath);
        unlink($resultPath);
        $readyPaths[] = $readyPath;
        $resultPaths[] = $resultPath;

        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('Unable to fork a concurrency worker.');
        }

        if ($pid === 0) {
            DB::purge();
            touch($readyPath);

            while (! file_exists($barrierPath)) {
                usleep(1000);
            }

            try {
                $created = app(AcademicHierarchyService::class)->create(
                    $organization,
                    $childType,
                    "Concurrent child {$worker}",
                    "CONCURRENT-{$worker}",
                    $root,
                );
                file_put_contents($resultPath, json_encode(['id' => $created->getKey(), 'ok' => true], JSON_THROW_ON_ERROR));
                exit(0);
            } catch (Throwable $exception) {
                file_put_contents($resultPath, json_encode(['error' => $exception->getMessage(), 'ok' => false], JSON_THROW_ON_ERROR));
                exit(1);
            }
        }

        $childPids[] = $pid;
    }

    try {
        $deadline = microtime(true) + 10;

        while (collect($readyPaths)->filter(fn (string $path): bool => file_exists($path))->count() < count($readyPaths)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Concurrency workers did not reach the barrier.');
            }

            usleep(1000);
        }

        touch($barrierPath);

        foreach ($childPids as $childPid) {
            pcntl_waitpid($childPid, $status);
            expect(pcntl_wifexited($status))->toBeTrue()
                ->and(pcntl_wexitstatus($status))->toBe(0);
        }

        foreach ($resultPaths as $resultPath) {
            $result = json_decode(file_get_contents($resultPath), true, flags: JSON_THROW_ON_ERROR);

            expect($result['ok'])->toBeTrue();
        }

        $tenantContext->run($organization, function () use ($organization, $root): void {
            $closureRows = DB::table('academic_unit_closure')
                ->where('organization_id', $organization->getKey())
                ->where('ancestor_id', $root->getKey())
                ->get();

            expect($closureRows)->toHaveCount(3)
                ->and($closureRows->pluck('descendant_id')->unique())->toHaveCount(3)
                ->and(DB::table('academic_units')->where('organization_id', $organization->getKey())->count())->toBe(3);
        });
    } finally {
        foreach (array_merge([$barrierPath], $readyPaths, $resultPaths) as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }
});
