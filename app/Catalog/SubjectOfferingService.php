<?php

namespace App\Catalog;

use App\Audit\AuditLogger;
use App\Enums\ResourceType;
use App\Enums\SubjectOfferingStatus;
use App\Models\AcademicPeriod;
use App\Models\AcademicUnit;
use App\Models\FacultyProfile;
use App\Models\OfferingComponent;
use App\Models\Organization;
use App\Models\StudentGroup;
use App\Models\Subject;
use App\Models\SubjectComponent;
use App\Models\SubjectOffering;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubjectOfferingService
{
    public function __construct(
        private TenantContext $tenantContext,
        private AuditLogger $auditLogger,
    ) {}

    public function createOffering(
        Organization $organization,
        AcademicPeriod $academicPeriod,
        Subject $subject,
        StudentGroup $studentGroup,
        ?string $code = null,
        int $expectedEnrollment = 0,
        SubjectOfferingStatus $status = SubjectOfferingStatus::Draft,
        ?AcademicUnit $owningAcademicUnit = null,
        ?User $actor = null,
    ): SubjectOffering {
        $this->assertEnrollment($expectedEnrollment);
        $this->assertCode($code);

        return $this->tenantContext->run($organization, function () use ($organization, $academicPeriod, $subject, $studentGroup, $code, $expectedEnrollment, $status, $owningAcademicUnit, $actor): SubjectOffering {
            return DB::transaction(function () use ($organization, $academicPeriod, $subject, $studentGroup, $code, $expectedEnrollment, $status, $owningAcademicUnit, $actor): SubjectOffering {
                $this->lockOrganization($organization);
                $period = $this->lockPeriod($organization, $academicPeriod);
                $lockedSubject = $this->lockSubject($organization, $subject);
                $group = $this->lockStudentGroup($organization, $studentGroup);

                if ($period->academic_year_id !== $group->academic_year_id) {
                    throw ValidationException::withMessages([
                        'student_group' => 'The student group must belong to the offering period academic year.',
                    ]);
                }

                $owningUnitId = $owningAcademicUnit === null
                    ? $group->academic_unit_id
                    : AcademicUnit::query()
                        ->whereKey($owningAcademicUnit->getKey())
                        ->where('organization_id', $organization->getKey())
                        ->lockForUpdate()
                        ->firstOrFail()
                        ->getKey();

                $offering = SubjectOffering::query()->create([
                    'organization_id' => $organization->getKey(),
                    'academic_period_id' => $period->getKey(),
                    'subject_id' => $lockedSubject->getKey(),
                    'student_group_id' => $group->getKey(),
                    'owning_academic_unit_id' => $owningUnitId,
                    'code' => $code,
                    'expected_enrollment' => $expectedEnrollment,
                    'status' => $status,
                ]);

                $components = SubjectComponent::query()
                    ->where('organization_id', $organization->getKey())
                    ->where('subject_id', $lockedSubject->getKey())
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                foreach ($components as $component) {
                    $this->createSnapshot($organization, $offering, $component);
                }

                $offering = $offering->fresh(['academicPeriod', 'subject', 'studentGroup', 'components']);

                $this->auditLogger->record(
                    action: 'subject_offering.created',
                    organization: $organization,
                    actor: $actor,
                    subject: $offering,
                    after: $this->offeringSnapshot($offering),
                );

                return $offering;
            }, attempts: 3);
        });
    }

    public function addComponentSnapshot(
        Organization $organization,
        SubjectOffering $offering,
        SubjectComponent $subjectComponent,
        ?User $actor = null,
    ): OfferingComponent {
        return $this->tenantContext->run($organization, function () use ($organization, $offering, $subjectComponent, $actor): OfferingComponent {
            return DB::transaction(function () use ($organization, $offering, $subjectComponent, $actor): OfferingComponent {
                $this->lockOrganization($organization);
                $lockedOffering = $this->lockOffering($organization, $offering);
                $lockedSubjectComponent = SubjectComponent::query()
                    ->whereKey($subjectComponent->getKey())
                    ->where('organization_id', $organization->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $component = $this->createSnapshot($organization, $lockedOffering, $lockedSubjectComponent);

                $this->auditLogger->record(
                    action: 'subject_offering.component_snapshot_created',
                    organization: $organization,
                    actor: $actor,
                    subject: $lockedOffering,
                    after: $this->componentSnapshot($component),
                );

                return $component;
            }, attempts: 3);
        });
    }

    public function assignInstructor(
        Organization $organization,
        OfferingComponent $component,
        FacultyProfile $faculty,
        int $loadPercentage = 100,
        bool $isPrimary = false,
        ?User $actor = null,
    ): OfferingComponent {
        $this->assertLoadPercentage($loadPercentage);

        return $this->tenantContext->run($organization, function () use ($organization, $component, $faculty, $loadPercentage, $isPrimary, $actor): OfferingComponent {
            return DB::transaction(function () use ($organization, $component, $faculty, $loadPercentage, $isPrimary, $actor): OfferingComponent {
                $this->lockOrganization($organization);
                $lockedComponent = $this->lockComponent($organization, $component);
                $lockedFaculty = FacultyProfile::query()
                    ->whereKey($faculty->getKey())
                    ->where('organization_id', $organization->getKey())
                    ->whereHas('resource', function ($query): void {
                        $query->where('type', ResourceType::Faculty)
                            ->where('is_active', true);
                    })
                    ->lockForUpdate()
                    ->firstOrFail();

                $before = DB::table('offering_instructors')
                    ->where('organization_id', $organization->getKey())
                    ->where('offering_component_id', $lockedComponent->getKey())
                    ->where('faculty_profile_id', $lockedFaculty->getKey())
                    ->first(['load_percentage', 'is_primary']);

                if ($isPrimary) {
                    DB::table('offering_instructors')
                        ->where('organization_id', $organization->getKey())
                        ->where('offering_component_id', $lockedComponent->getKey())
                        ->update(['is_primary' => false]);
                }

                DB::table('offering_instructors')->updateOrInsert(
                    [
                        'organization_id' => $organization->getKey(),
                        'offering_component_id' => $lockedComponent->getKey(),
                        'faculty_profile_id' => $lockedFaculty->getKey(),
                    ],
                    [
                        'load_percentage' => $loadPercentage,
                        'is_primary' => $isPrimary,
                    ],
                );

                $this->auditLogger->record(
                    action: 'subject_offering.instructor_assigned',
                    organization: $organization,
                    actor: $actor,
                    subject: $lockedComponent,
                    before: $before === null ? null : ['faculty_profile_id' => $lockedFaculty->public_id, 'load_percentage' => (int) $before->load_percentage, 'is_primary' => (bool) $before->is_primary],
                    after: ['faculty_profile_id' => $lockedFaculty->public_id, 'load_percentage' => $loadPercentage, 'is_primary' => $isPrimary],
                );

                return $lockedComponent->fresh(['instructors']);
            }, attempts: 3);
        });
    }

    public function removeInstructor(
        Organization $organization,
        OfferingComponent $component,
        FacultyProfile $faculty,
        ?User $actor = null,
    ): OfferingComponent {
        return $this->tenantContext->run($organization, function () use ($organization, $component, $faculty, $actor): OfferingComponent {
            return DB::transaction(function () use ($organization, $component, $faculty, $actor): OfferingComponent {
                $this->lockOrganization($organization);
                $lockedComponent = $this->lockComponent($organization, $component);
                $lockedFaculty = FacultyProfile::query()
                    ->whereKey($faculty->getKey())
                    ->where('organization_id', $organization->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $deleted = DB::table('offering_instructors')
                    ->where('organization_id', $organization->getKey())
                    ->where('offering_component_id', $lockedComponent->getKey())
                    ->where('faculty_profile_id', $lockedFaculty->getKey())
                    ->delete();

                if ($deleted === 1) {
                    $this->auditLogger->record(
                        action: 'subject_offering.instructor_removed',
                        organization: $organization,
                        actor: $actor,
                        subject: $lockedComponent,
                        before: ['faculty_profile_id' => $lockedFaculty->public_id],
                    );
                }

                return $lockedComponent->fresh(['instructors']);
            }, attempts: 3);
        });
    }

    public function updateStatus(
        Organization $organization,
        SubjectOffering $offering,
        SubjectOfferingStatus $status,
        ?User $actor = null,
    ): SubjectOffering {
        return $this->tenantContext->run($organization, function () use ($organization, $offering, $status, $actor): SubjectOffering {
            return DB::transaction(function () use ($organization, $offering, $status, $actor): SubjectOffering {
                $this->lockOrganization($organization);
                $lockedOffering = $this->lockOffering($organization, $offering);
                $before = ['status' => $lockedOffering->status->value];
                $lockedOffering->update(['status' => $status]);
                $lockedOffering->refresh();

                $this->auditLogger->record(
                    action: 'subject_offering.status_updated',
                    organization: $organization,
                    actor: $actor,
                    subject: $lockedOffering,
                    before: $before,
                    after: ['status' => $lockedOffering->status->value],
                );

                return $lockedOffering->load('components');
            }, attempts: 3);
        });
    }

    private function createSnapshot(
        Organization $organization,
        SubjectOffering $offering,
        SubjectComponent $subjectComponent,
    ): OfferingComponent {
        $requiredRoomTypeId = DB::table('subject_component_room_types')
            ->where('organization_id', $organization->getKey())
            ->where('subject_component_id', $subjectComponent->getKey())
            ->orderBy('room_type_id')
            ->value('room_type_id');
        $component = OfferingComponent::query()->create([
            'organization_id' => $organization->getKey(),
            'subject_offering_id' => $offering->getKey(),
            'subject_component_id' => $subjectComponent->getKey(),
            'kind' => $subjectComponent->kind,
            'name' => $subjectComponent->name,
            'weekly_minutes' => $subjectComponent->weekly_minutes,
            'sessions_per_week' => $subjectComponent->sessions_per_week,
            'duration_minutes' => $subjectComponent->default_duration_minutes,
            'minimum_room_capacity' => $subjectComponent->minimum_room_capacity,
            'required_room_type_id' => $requiredRoomTypeId,
            'delivery_mode' => $subjectComponent->delivery_mode,
        ]);

        $features = DB::table('subject_component_features')
            ->where('organization_id', $organization->getKey())
            ->where('subject_component_id', $subjectComponent->getKey())
            ->get(['feature_id', 'minimum_quantity']);

        foreach ($features as $feature) {
            DB::table('offering_component_features')->insert([
                'organization_id' => $organization->getKey(),
                'offering_component_id' => $component->getKey(),
                'feature_id' => $feature->feature_id,
                'minimum_quantity' => $feature->minimum_quantity,
            ]);
        }

        return $component;
    }

    private function lockOrganization(Organization $organization): Organization
    {
        return Organization::query()
            ->whereKey($organization->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockPeriod(Organization $organization, AcademicPeriod $period): AcademicPeriod
    {
        return AcademicPeriod::query()
            ->whereKey($period->getKey())
            ->where('organization_id', $organization->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockSubject(Organization $organization, Subject $subject): Subject
    {
        return Subject::query()
            ->whereKey($subject->getKey())
            ->where('organization_id', $organization->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockStudentGroup(Organization $organization, StudentGroup $studentGroup): StudentGroup
    {
        return StudentGroup::query()
            ->whereKey($studentGroup->getKey())
            ->where('organization_id', $organization->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockOffering(Organization $organization, SubjectOffering $offering): SubjectOffering
    {
        return SubjectOffering::query()
            ->whereKey($offering->getKey())
            ->where('organization_id', $organization->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockComponent(Organization $organization, OfferingComponent $component): OfferingComponent
    {
        return OfferingComponent::query()
            ->whereKey($component->getKey())
            ->where('organization_id', $organization->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertCode(?string $code): void
    {
        if ($code !== null && trim($code) === '') {
            throw ValidationException::withMessages(['code' => 'Offering codes must not be blank.']);
        }
    }

    private function assertEnrollment(int $expectedEnrollment): void
    {
        if ($expectedEnrollment < 0) {
            throw ValidationException::withMessages(['expected_enrollment' => 'Expected enrollment cannot be negative.']);
        }
    }

    private function assertLoadPercentage(int $loadPercentage): void
    {
        if ($loadPercentage < 1 || $loadPercentage > 100) {
            throw ValidationException::withMessages(['load_percentage' => 'Instructor load percentage must be between one and 100.']);
        }
    }

    /**
     * @return array{id: string, academic_period_id: string, subject_id: string, student_group_id: string, code: string|null, expected_enrollment: int, status: string, component_count: int}
     */
    private function offeringSnapshot(SubjectOffering $offering): array
    {
        return [
            'id' => $offering->public_id,
            'academic_period_id' => $offering->academicPeriod->public_id,
            'subject_id' => $offering->subject->public_id,
            'student_group_id' => $offering->studentGroup->public_id,
            'code' => $offering->code,
            'expected_enrollment' => $offering->expected_enrollment,
            'status' => $offering->status->value,
            'component_count' => $offering->components->count(),
        ];
    }

    /**
     * @return array{id: string, name: string, kind: string, weekly_minutes: int, sessions_per_week: int, duration_minutes: int, delivery_mode: string}
     */
    private function componentSnapshot(OfferingComponent $component): array
    {
        return [
            'id' => $component->public_id,
            'name' => $component->name,
            'kind' => $component->kind->value,
            'weekly_minutes' => $component->weekly_minutes,
            'sessions_per_week' => $component->sessions_per_week,
            'duration_minutes' => $component->duration_minutes,
            'delivery_mode' => $component->delivery_mode->value,
        ];
    }
}
