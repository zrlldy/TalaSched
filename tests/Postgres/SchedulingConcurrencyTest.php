<?php

use App\Enums\AcademicPeriodKind;
use App\Enums\AcademicYearStatus;
use App\Enums\ResourceType;
use App\Enums\ScheduleResourceRole;
use App\Enums\SubjectComponentKind;
use App\Enums\TimetableVersionStatus;
use App\Exceptions\ScheduleConflictException;
use App\Models\AcademicCalendar;
use App\Models\AcademicPeriod;
use App\Models\AcademicUnit;
use App\Models\AcademicUnitType;
use App\Models\AcademicYear;
use App\Models\FacultyProfile;
use App\Models\OfferingComponent;
use App\Models\Organization;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\ScheduleEntry;
use App\Models\SchedulingResource;
use App\Models\StudentGroup;
use App\Models\Subject;
use App\Models\SubjectOffering;
use App\Models\Timetable;
use App\Models\TimetableVersion;
use App\Scheduling\CreateScheduleEntry;
use App\Scheduling\ScheduleEntryData;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

/**
 * @param  array{left: ScheduleEntryData, right: ScheduleEntryData, shared_public_id: string}  $scenario
 * @return list<array{ok: bool, issues: list<array<string, mixed>>, error: string|null}>
 */
function runConcurrentSchedulePair(Organization $organization, array $scenario): array
{
    $barrierPath = tempnam(sys_get_temp_dir(), 'talasched-schedule-barrier-');
    $readyPaths = [];
    $resultPaths = [];
    $childPids = [];

    if ($barrierPath === false) {
        throw new RuntimeException('Unable to create the scheduling concurrency barrier.');
    }

    unlink($barrierPath);

    foreach (['left', 'right'] as $side) {
        $readyPath = tempnam(sys_get_temp_dir(), 'talasched-schedule-ready-');
        $resultPath = tempnam(sys_get_temp_dir(), 'talasched-schedule-result-');

        if ($readyPath === false || $resultPath === false) {
            throw new RuntimeException('Unable to create scheduling concurrency worker files.');
        }

        unlink($readyPath);
        unlink($resultPath);
        $readyPaths[] = $readyPath;
        $resultPaths[] = $resultPath;

        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('Unable to fork a scheduling concurrency worker.');
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
                app(CreateScheduleEntry::class)->handle($organization, $scenario[$side]);
                file_put_contents($resultPath, json_encode([
                    'ok' => true,
                    'issues' => [],
                    'error' => null,
                ], JSON_THROW_ON_ERROR));
            } catch (Throwable $exception) {
                file_put_contents($resultPath, json_encode([
                    'ok' => false,
                    'issues' => $exception instanceof ScheduleConflictException ? $exception->issues : [],
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

        while (collect($readyPaths)->filter('file_exists')->count() < count($readyPaths)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Scheduling concurrency workers did not reach the barrier.');
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

/**
 * @return array{left: ScheduleEntryData, right: ScheduleEntryData, shared_public_id: string}
 */
function makeConcurrentScheduleScenario(
    Organization $organization,
    AcademicYear $year,
    AcademicPeriod $period,
    AcademicUnit $unit,
    TimetableVersion $version,
    string $target,
): array {
    $makeFaculty = static function (string $name) use ($organization): array {
        $resource = SchedulingResource::create([
            'organization_id' => $organization->getKey(),
            'type' => ResourceType::Faculty,
            'name' => $name,
        ]);
        $profile = FacultyProfile::create([
            'organization_id' => $organization->getKey(),
            'scheduling_resource_id' => $resource->getKey(),
            'employee_number' => $name,
            'maximum_daily_minutes' => 1200,
            'maximum_weekly_minutes' => 6000,
        ]);

        return ['profile' => $profile, 'resource' => $resource];
    };
    $roomType = RoomType::factory()->forOrganization($organization)->create([
        'code' => Str::lower($target).'-classroom',
        'name' => 'Concurrency classroom',
    ]);
    $makeRoom = static function (string $name) use ($organization, $roomType): array {
        $resource = SchedulingResource::create([
            'organization_id' => $organization->getKey(),
            'type' => ResourceType::Room,
            'name' => $name,
        ]);
        $room = Room::create([
            'organization_id' => $organization->getKey(),
            'scheduling_resource_id' => $resource->getKey(),
            'room_type_id' => $roomType->getKey(),
            'code' => $name,
            'name' => $name,
            'capacity' => 100,
        ]);

        return ['room' => $room, 'resource' => $resource];
    };
    $makeGroup = static function (string $name) use ($organization, $year, $unit): StudentGroup {
        $resource = SchedulingResource::create([
            'organization_id' => $organization->getKey(),
            'type' => ResourceType::StudentGroup,
            'name' => $name,
        ]);

        return StudentGroup::create([
            'organization_id' => $organization->getKey(),
            'scheduling_resource_id' => $resource->getKey(),
            'academic_year_id' => $year->getKey(),
            'academic_unit_id' => $unit->getKey(),
            'code' => $name,
            'name' => $name,
            'expected_headcount' => 40,
        ]);
    };

    $sharedFaculty = $target === 'teacher' ? $makeFaculty('Shared teacher') : null;
    $sharedRoom = $target === 'room' ? $makeRoom('Shared room') : null;
    $sharedGroup = $target === 'group' ? $makeGroup('Shared group') : null;
    $leftFaculty = $sharedFaculty ?? $makeFaculty("{$target} left teacher");
    $rightFaculty = $sharedFaculty ?? $makeFaculty("{$target} right teacher");
    $leftRoom = $sharedRoom ?? $makeRoom("{$target} left room");
    $rightRoom = $sharedRoom ?? $makeRoom("{$target} right room");
    $leftGroup = $sharedGroup ?? $makeGroup("{$target} left group");
    $rightGroup = $sharedGroup ?? $makeGroup("{$target} right group");
    $makeComponent = static function (string $name, StudentGroup $group, FacultyProfile $faculty) use ($organization, $period, $unit): OfferingComponent {
        $subject = Subject::create([
            'organization_id' => $organization->getKey(),
            'code' => "{$name}-subject",
            'name' => "{$name} subject",
        ]);
        $offering = SubjectOffering::create([
            'organization_id' => $organization->getKey(),
            'academic_period_id' => $period->getKey(),
            'subject_id' => $subject->getKey(),
            'student_group_id' => $group->getKey(),
            'owning_academic_unit_id' => $unit->getKey(),
            'code' => "{$name}-offering",
            'expected_enrollment' => 40,
        ]);
        $component = OfferingComponent::create([
            'organization_id' => $organization->getKey(),
            'subject_offering_id' => $offering->getKey(),
            'kind' => SubjectComponentKind::Lecture,
            'name' => "{$name} lecture",
            'weekly_minutes' => 90,
            'sessions_per_week' => 1,
            'duration_minutes' => 90,
        ]);
        DB::table('offering_instructors')->insert([
            'organization_id' => $organization->getKey(),
            'offering_component_id' => $component->getKey(),
            'faculty_profile_id' => $faculty->getKey(),
            'load_percentage' => 100,
            'is_primary' => true,
        ]);

        return $component;
    };
    $leftComponent = $makeComponent("{$target}-left", $leftGroup, $leftFaculty['profile']);
    $rightComponent = $makeComponent("{$target}-right", $rightGroup, $rightFaculty['profile']);
    $makeData = static function (OfferingComponent $component, array $faculty, array $room, StudentGroup $group) use ($version): ScheduleEntryData {
        return new ScheduleEntryData(
            timetableVersionId: $version->getKey(),
            offeringComponentId: $component->getKey(),
            weekday: 1,
            startsAtMinute: 480,
            endsAtMinute: 570,
            resources: [
                ['resource_id' => $faculty['resource']->getKey(), 'role' => ScheduleResourceRole::Instructor->value],
                ['resource_id' => $group->resource->getKey(), 'role' => ScheduleResourceRole::StudentGroup->value],
                ['resource_id' => $room['resource']->getKey(), 'role' => ScheduleResourceRole::Room->value],
            ],
        );
    };

    $sharedResource = match ($target) {
        'teacher' => $sharedFaculty['resource'],
        'room' => $sharedRoom['resource'],
        'group' => $sharedGroup->resource,
    };

    return [
        'left' => $makeData($leftComponent, $leftFaculty, $leftRoom, $leftGroup),
        'right' => $makeData($rightComponent, $rightFaculty, $rightRoom, $rightGroup),
        'shared_public_id' => $sharedResource->public_id,
    ];
}

test('concurrent scheduling requests cannot double-book teachers rooms or groups', function (): void {
    if (DB::getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('This integration test requires PostgreSQL and pcntl.');
    }

    try {
        DB::connection()->getPdo();
    } catch (Throwable) {
        $this->markTestSkipped('This integration test requires a reachable PostgreSQL test database.');
    }

    $organization = Organization::factory()->create();
    $tenantContext = app(TenantContext::class);
    $scenarios = $tenantContext->run($organization, function () use ($organization): array {
        $year = AcademicYear::create([
            'organization_id' => $organization->getKey(),
            'name' => '2026-2027 concurrency',
            'starts_on' => '2026-06-01',
            'ends_on' => '2027-05-31',
            'status' => AcademicYearStatus::Active,
        ]);
        $period = AcademicPeriod::create([
            'organization_id' => $organization->getKey(),
            'academic_year_id' => $year->getKey(),
            'name' => 'Concurrency period',
            'kind' => AcademicPeriodKind::Semester,
            'sequence' => 1,
            'starts_on' => '2026-06-01',
            'ends_on' => '2026-10-31',
        ]);
        AcademicCalendar::create([
            'organization_id' => $organization->getKey(),
            'academic_period_id' => $period->getKey(),
            'weekday' => 1,
            'starts_at_minute' => 420,
            'ends_at_minute' => 1200,
        ]);
        $unitType = AcademicUnitType::create([
            'organization_id' => $organization->getKey(),
            'code' => 'concurrency-program',
            'name' => 'Concurrency program',
        ]);
        $unit = AcademicUnit::create([
            'organization_id' => $organization->getKey(),
            'academic_unit_type_id' => $unitType->getKey(),
            'code' => 'CONCURRENCY',
            'name' => 'Concurrency unit',
        ]);
        $timetable = Timetable::create([
            'organization_id' => $organization->getKey(),
            'academic_year_id' => $year->getKey(),
            'academic_period_id' => $period->getKey(),
            'name' => 'Concurrency timetable',
            'timezone' => 'Asia/Manila',
            'scheduling_granularity' => 30,
        ]);
        $version = TimetableVersion::create([
            'organization_id' => $organization->getKey(),
            'timetable_id' => $timetable->getKey(),
            'version_number' => 1,
            'status' => TimetableVersionStatus::Draft,
        ]);

        return [
            'teacher' => makeConcurrentScheduleScenario($organization, $year, $period, $unit, $version, 'teacher'),
            'room' => makeConcurrentScheduleScenario($organization, $year, $period, $unit, $version, 'room'),
            'group' => makeConcurrentScheduleScenario($organization, $year, $period, $unit, $version, 'group'),
        ];
    });

    foreach ($scenarios as $target => $scenario) {
        $results = runConcurrentSchedulePair($organization, $scenario);

        expect(collect($results)->where('ok', true)->count())->toBe(1)
            ->and(collect($results)->where('ok', false)->count())->toBe(1);

        $failure = collect($results)->firstWhere('ok', false);
        $overlap = collect($failure['issues'])->firstWhere('code', 'resource_overlap');

        expect($overlap)->not->toBeNull()
            ->and($overlap['severity'])->toBe('hard')
            ->and($overlap['resource']['id'])->toBe($scenario['shared_public_id']);
    }

    $tenantContext->run($organization, function (): void {
        expect(ScheduleEntry::query()->count())->toBe(3)
            ->and(DB::table('schedule_reservations')->count())->toBe(9);
    });
});
