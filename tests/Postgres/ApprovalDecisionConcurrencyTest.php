<?php

use App\Approvals\ActivateApprovalWorkflowVersion;
use App\Approvals\CreateApprovalWorkflowVersion;
use App\Approvals\DecideTimetableApproval;
use App\Approvals\SubmitTimetableForApproval;
use App\Enums\ApprovalDecision;
use App\Enums\OrganizationPermission;
use App\Enums\OrganizationRole;
use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\Organization;
use App\Models\Timetable;
use App\Models\TimetableVersion;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Throwable;

uses(TestCase::class);

/**
 * @return list<array{ok: bool, error: string|null}>
 */
function runConcurrentApprovalDecisionPair(Organization $organization, int $instanceId, User $left, User $right): array
{
    $barrierPath = tempnam(sys_get_temp_dir(), 'talasched-approval-barrier-');
    $readyPaths = [];
    $resultPaths = [];
    $childPids = [];

    if ($barrierPath === false) {
        throw new RuntimeException('Unable to create the approval concurrency barrier.');
    }

    unlink($barrierPath);

    foreach ([$left, $right] as $actor) {
        $readyPath = tempnam(sys_get_temp_dir(), 'talasched-approval-ready-');
        $resultPath = tempnam(sys_get_temp_dir(), 'talasched-approval-result-');

        if ($readyPath === false || $resultPath === false) {
            throw new RuntimeException('Unable to create approval concurrency worker files.');
        }

        unlink($readyPath);
        unlink($resultPath);
        $readyPaths[] = $readyPath;
        $resultPaths[] = $resultPath;

        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('Unable to fork an approval concurrency worker.');
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
                app(DecideTimetableApproval::class)->handle(
                    approvalInstanceId: $instanceId,
                    decision: ApprovalDecision::Approve,
                    idempotencyKey: (string) Str::uuid(),
                    actor: $actor,
                );
                file_put_contents($resultPath, json_encode(['ok' => true, 'error' => null], JSON_THROW_ON_ERROR));
            } catch (Throwable $exception) {
                file_put_contents($resultPath, json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR));
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
                throw new RuntimeException('Approval concurrency workers did not reach the barrier.');
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

test('concurrent approval decisions serialize one final action', function (): void {
    if (DB::getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('This integration test requires PostgreSQL and pcntl.');
    }

    $submitter = User::factory()->withOwnedOrganization()->create();
    $organization = $submitter->currentOrganization;
    grantApprovalWorkflowsEntitlement($organization);
    $left = User::factory()->create();
    $right = User::factory()->create();
    $organization->members()->attach($left, ['role' => OrganizationRole::Admin]);
    $organization->members()->attach($right, ['role' => OrganizationRole::Admin]);

    $year = AcademicYear::factory()->forOrganization($organization)->create(['status' => 'active']);
    $period = AcademicPeriod::factory()->forAcademicYear($year)->create();
    $timetable = Timetable::factory()->create([
        'organization_id' => $organization->id,
        'academic_year_id' => $year->id,
        'academic_period_id' => $period->id,
    ]);
    $version = TimetableVersion::factory()->create([
        'organization_id' => $organization->id,
        'timetable_id' => $timetable->id,
        'created_by' => $submitter->id,
    ]);
    $workflowVersionId = app(CreateApprovalWorkflowVersion::class)->handle(
        $organization,
        $submitter,
        'Concurrent approvals',
        [[
            'sequence' => 1,
            'label' => 'Scheduling review',
            'approver_selector_type' => 'permission',
            'required_permission' => OrganizationPermission::ManageScheduling->value,
            'role_codes' => [],
            'minimum_approvals' => 1,
            'allow_self_approval' => false,
            'signatory_slot' => 'registrar',
        ]],
    );
    app(ActivateApprovalWorkflowVersion::class)->handle($organization, $submitter, $workflowVersionId);
    $instanceId = app(SubmitTimetableForApproval::class)->handle($version, $workflowVersionId, $submitter);

    $results = runConcurrentApprovalDecisionPair($organization, $instanceId, $left, $right);

    expect(collect($results)->where('ok', true)->count())->toBe(1)
        ->and(collect($results)->where('ok', false)->count())->toBe(1)
        ->and(DB::table('approval_actions')->where('approval_instance_step_id', DB::table('approval_instance_steps')->where('approval_instance_id', $instanceId)->value('id'))->count())->toBe(1)
        ->and(DB::table('approval_instances')->whereKey($instanceId)->value('status'))->toBe('approved');
});
