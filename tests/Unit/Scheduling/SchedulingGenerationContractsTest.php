<?php

use App\Enums\ConstraintSeverity;
use App\Enums\GenerationStatus;
use App\Scheduling\ConstraintIssue;
use App\Scheduling\Contracts\GenerationCancellation;
use App\Scheduling\Contracts\GenerationProgressReporter;
use App\Scheduling\Contracts\SchedulingEngine;
use App\Scheduling\Data\GenerationProgress;
use App\Scheduling\Data\GenerationResult;
use App\Scheduling\Data\SchedulingProblem;
use App\Scheduling\Data\SchedulingSolution;

test('scheduling problems serialize an implementation-neutral public snapshot', function (): void {
    $problem = schedulingProblem();

    expect($problem->toArray())->toBe([
        'schema_version' => 1,
        'organization_id' => 'org-01',
        'timetable_id' => 'timetable-01',
        'source_version_id' => 'version-01',
        'timezone' => 'Asia/Manila',
        'granularity_minutes' => 30,
        'requirements' => [[
            'id' => 'requirement-01',
            'offering_component_id' => 'component-01',
            'duration_minutes' => 90,
            'delivery_mode' => 'physical',
            'required_resources' => [
                ['resource_id' => 'group-01', 'role' => 'student_group'],
            ],
            'candidate_resources' => [
                'instructor' => ['faculty-01'],
                'room' => ['room-01'],
            ],
            'allowed_weekdays' => [1, 3],
        ]],
        'fixed_entries' => [],
        'constraints' => [[
            'code' => 'resource_overlap',
            'severity' => 'hard',
            'priority' => 100,
            'weight' => 1.0,
            'configuration' => [],
        ]],
        'seed' => 42,
    ]);
});

test('scheduling engines report progress, honor cancellation, and return canonical explanations', function (): void {
    $progressReporter = new class implements GenerationProgressReporter
    {
        /** @var list<GenerationProgress> */
        public array $reported = [];

        public function report(GenerationProgress $progress): void
        {
            $this->reported[] = $progress;
        }
    };

    $engine = new class implements SchedulingEngine
    {
        public function generate(
            SchedulingProblem $problem,
            GenerationCancellation $cancellation,
            GenerationProgressReporter $progressReporter,
        ): GenerationResult {
            if ($cancellation->isCancellationRequested()) {
                $progressReporter->report(new GenerationProgress(
                    runPublicId: 'generation-01',
                    status: GenerationStatus::Cancelled,
                    message: 'Generation cancelled.',
                ));

                return new GenerationResult(status: GenerationStatus::Cancelled);
            }

            $progressReporter->report(new GenerationProgress(
                runPublicId: 'generation-01',
                status: GenerationStatus::Running,
                percent: null,
                message: 'Evaluating requirements.',
                details: ['requirements_processed' => count($problem->requirements)],
            ));

            return new GenerationResult(
                status: GenerationStatus::Completed,
                solution: new SchedulingSolution(
                    entries: [[
                        'requirement_id' => 'requirement-01',
                        'weekday' => 1,
                        'starts_at_minute' => 480,
                        'ends_at_minute' => 570,
                        'resources' => [
                            ['resource_id' => 'group-01', 'role' => 'student_group'],
                            ['resource_id' => 'faculty-01', 'role' => 'instructor'],
                            ['resource_id' => 'room-01', 'role' => 'room'],
                        ],
                    ]],
                    score: 12.5,
                ),
                explanations: [
                    new ConstraintIssue(
                        code: 'late_session',
                        severity: ConstraintSeverity::Soft,
                        field: 'starts_at_minute',
                        message: 'The selected session starts late in the day.',
                        details: ['penalty' => 2.5],
                    ),
                ],
            );
        }
    };

    $notCancelled = new class implements GenerationCancellation
    {
        public function isCancellationRequested(): bool
        {
            return false;
        }
    };
    $cancelled = new class implements GenerationCancellation
    {
        public function isCancellationRequested(): bool
        {
            return true;
        }
    };

    $result = $engine->generate(schedulingProblem(), $notCancelled, $progressReporter);
    $cancelledResult = $engine->generate(schedulingProblem(), $cancelled, $progressReporter);

    expect($result->toArray())->toBe([
        'status' => 'completed',
        'solution' => [
            'entries' => [[
                'requirement_id' => 'requirement-01',
                'weekday' => 1,
                'starts_at_minute' => 480,
                'ends_at_minute' => 570,
                'resources' => [
                    ['resource_id' => 'group-01', 'role' => 'student_group'],
                    ['resource_id' => 'faculty-01', 'role' => 'instructor'],
                    ['resource_id' => 'room-01', 'role' => 'room'],
                ],
            ]],
            'score' => 12.5,
        ],
        'explanations' => [[
            'code' => 'late_session',
            'severity' => 'soft',
            'field' => 'starts_at_minute',
            'rule_code' => 'late_session',
            'message' => 'The selected session starts late in the day.',
            'details' => ['penalty' => 2.5],
            'resource' => null,
            'conflicting_entry_id' => null,
        ]],
    ])
        ->and($cancelledResult->status)->toBe(GenerationStatus::Cancelled)
        ->and($cancelledResult->solution)->toBeNull()
        ->and($progressReporter->reported)->toHaveCount(2)
        ->and($progressReporter->reported[0]->toArray())->toBe([
            'run_id' => 'generation-01',
            'status' => 'running',
            'percent' => null,
            'message' => 'Evaluating requirements.',
            'details' => ['requirements_processed' => 1],
        ])
        ->and($progressReporter->reported[1]->status)->toBe(GenerationStatus::Cancelled);
});

function schedulingProblem(): SchedulingProblem
{
    return new SchedulingProblem(
        organizationPublicId: 'org-01',
        timetablePublicId: 'timetable-01',
        sourceVersionPublicId: 'version-01',
        timezone: 'Asia/Manila',
        granularityMinutes: 30,
        requirements: [[
            'id' => 'requirement-01',
            'offering_component_id' => 'component-01',
            'duration_minutes' => 90,
            'delivery_mode' => 'physical',
            'required_resources' => [
                ['resource_id' => 'group-01', 'role' => 'student_group'],
            ],
            'candidate_resources' => [
                'instructor' => ['faculty-01'],
                'room' => ['room-01'],
            ],
            'allowed_weekdays' => [1, 3],
        ]],
        fixedEntries: [],
        constraints: [[
            'code' => 'resource_overlap',
            'severity' => 'hard',
            'priority' => 100,
            'weight' => 1.0,
            'configuration' => [],
        ]],
        seed: 42,
    );
}
