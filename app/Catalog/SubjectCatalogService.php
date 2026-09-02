<?php

namespace App\Catalog;

use App\Audit\AuditLogger;
use App\Enums\DeliveryMode;
use App\Enums\SubjectComponentKind;
use App\Models\Feature;
use App\Models\Organization;
use App\Models\RoomType;
use App\Models\Subject;
use App\Models\SubjectComponent;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubjectCatalogService
{
    public function __construct(
        private TenantContext $tenantContext,
        private AuditLogger $auditLogger,
    ) {}

    public function createSubject(
        Organization $organization,
        string $code,
        string $name,
        ?float $units = null,
        ?string $description = null,
        ?User $actor = null,
    ): Subject {
        $this->assertLabel($code, 'subject_code');
        $this->assertLabel($name, 'subject_name');
        $this->assertUnits($units);

        return $this->tenantContext->run($organization, function () use ($organization, $code, $name, $units, $description, $actor): Subject {
            return DB::transaction(function () use ($organization, $code, $name, $units, $description, $actor): Subject {
                $this->lockOrganization($organization);

                $subject = Subject::query()->create([
                    'organization_id' => $organization->getKey(),
                    'code' => $code,
                    'name' => $name,
                    'units' => $units,
                    'description' => $description,
                    'is_active' => true,
                ]);

                $this->auditLogger->record(
                    action: 'subject.created',
                    organization: $organization,
                    actor: $actor,
                    subject: $subject,
                    after: $this->subjectSnapshot($subject),
                );

                return $subject;
            }, attempts: 3);
        });
    }

    public function updateSubject(
        Organization $organization,
        Subject $subject,
        string $name,
        ?float $units = null,
        ?string $description = null,
        bool $isActive = true,
        ?User $actor = null,
    ): Subject {
        $this->assertLabel($name, 'subject_name');
        $this->assertUnits($units);

        return $this->tenantContext->run($organization, function () use ($organization, $subject, $name, $units, $description, $isActive, $actor): Subject {
            return DB::transaction(function () use ($organization, $subject, $name, $units, $description, $isActive, $actor): Subject {
                $this->lockOrganization($organization);
                $lockedSubject = $this->lockSubject($organization, $subject);
                $before = $this->subjectSnapshot($lockedSubject);
                $lockedSubject->update([
                    'name' => $name,
                    'units' => $units,
                    'description' => $description,
                    'is_active' => $isActive,
                ]);

                $lockedSubject->refresh();

                $this->auditLogger->record(
                    action: 'subject.updated',
                    organization: $organization,
                    actor: $actor,
                    subject: $lockedSubject,
                    before: $before,
                    after: $this->subjectSnapshot($lockedSubject),
                );

                return $lockedSubject;
            }, attempts: 3);
        });
    }

    public function addComponent(
        Organization $organization,
        Subject $subject,
        SubjectComponentKind $kind,
        string $name,
        int $weeklyMinutes,
        int $sessionsPerWeek,
        int $defaultDurationMinutes,
        ?int $minimumRoomCapacity = null,
        DeliveryMode $deliveryMode = DeliveryMode::Physical,
        ?User $actor = null,
    ): SubjectComponent {
        $this->assertLabel($name, 'component_name');
        $this->assertSchedulingValues($weeklyMinutes, $sessionsPerWeek, $defaultDurationMinutes, $minimumRoomCapacity);

        return $this->tenantContext->run($organization, function () use ($organization, $subject, $kind, $name, $weeklyMinutes, $sessionsPerWeek, $defaultDurationMinutes, $minimumRoomCapacity, $deliveryMode, $actor): SubjectComponent {
            return DB::transaction(function () use ($organization, $subject, $kind, $name, $weeklyMinutes, $sessionsPerWeek, $defaultDurationMinutes, $minimumRoomCapacity, $deliveryMode, $actor): SubjectComponent {
                $this->lockOrganization($organization);
                $lockedSubject = $this->lockSubject($organization, $subject);

                $component = SubjectComponent::query()->create([
                    'organization_id' => $organization->getKey(),
                    'subject_id' => $lockedSubject->getKey(),
                    'kind' => $kind,
                    'name' => $name,
                    'weekly_minutes' => $weeklyMinutes,
                    'sessions_per_week' => $sessionsPerWeek,
                    'default_duration_minutes' => $defaultDurationMinutes,
                    'minimum_room_capacity' => $minimumRoomCapacity,
                    'delivery_mode' => $deliveryMode,
                ]);

                $this->auditLogger->record(
                    action: 'subject.component_created',
                    organization: $organization,
                    actor: $actor,
                    subject: $lockedSubject,
                    after: $this->componentSnapshot($component),
                );

                return $component;
            }, attempts: 3);
        });
    }

    /**
     * Replace the room-type and feature requirements for a subject component.
     *
     * @param  array<int, RoomType>  $roomTypes
     * @param  array<int, array{feature: Feature, minimum_quantity?: int|null}>  $features
     */
    public function setRequirements(
        Organization $organization,
        SubjectComponent $component,
        array $roomTypes,
        array $features,
        ?User $actor = null,
    ): SubjectComponent {
        $this->assertFeatureRequirements($features);

        return $this->tenantContext->run($organization, function () use ($organization, $component, $roomTypes, $features, $actor): SubjectComponent {
            return DB::transaction(function () use ($organization, $component, $roomTypes, $features, $actor): SubjectComponent {
                $this->lockOrganization($organization);
                $lockedComponent = $this->lockComponent($organization, $component);
                $lockedComponent->load(['subject', 'roomTypes', 'features']);
                $before = $this->requirementsSnapshot($lockedComponent);
                $roomTypeIds = $this->lockRoomTypeIds($organization, $roomTypes);
                $featureRequirements = $this->lockFeatureRequirements($organization, $features);

                DB::table('subject_component_room_types')
                    ->where('organization_id', $organization->getKey())
                    ->where('subject_component_id', $lockedComponent->getKey())
                    ->delete();

                foreach ($roomTypeIds as $roomTypeId) {
                    DB::table('subject_component_room_types')->insert([
                        'organization_id' => $organization->getKey(),
                        'subject_component_id' => $lockedComponent->getKey(),
                        'room_type_id' => $roomTypeId,
                    ]);
                }

                DB::table('subject_component_features')
                    ->where('organization_id', $organization->getKey())
                    ->where('subject_component_id', $lockedComponent->getKey())
                    ->delete();

                foreach ($featureRequirements as $featureRequirement) {
                    DB::table('subject_component_features')->insert([
                        'organization_id' => $organization->getKey(),
                        'subject_component_id' => $lockedComponent->getKey(),
                        'feature_id' => $featureRequirement['feature_id'],
                        'minimum_quantity' => $featureRequirement['minimum_quantity'],
                    ]);
                }

                $lockedComponent = $lockedComponent->fresh(['subject', 'roomTypes', 'features']);

                $this->auditLogger->record(
                    action: 'subject.component_requirements_saved',
                    organization: $organization,
                    actor: $actor,
                    subject: $lockedComponent->subject,
                    before: $before,
                    after: $this->requirementsSnapshot($lockedComponent),
                );

                return $lockedComponent;
            }, attempts: 3);
        });
    }

    public function archiveSubject(Organization $organization, Subject $subject, ?User $actor = null): Subject
    {
        return $this->tenantContext->run($organization, function () use ($organization, $subject, $actor): Subject {
            return DB::transaction(function () use ($organization, $subject, $actor): Subject {
                $this->lockOrganization($organization);
                $lockedSubject = $this->lockSubject($organization, $subject);
                $before = $this->subjectSnapshot($lockedSubject);
                $lockedSubject->update(['is_active' => false]);
                $lockedSubject->delete();

                $this->auditLogger->record(
                    action: 'subject.archived',
                    organization: $organization,
                    actor: $actor,
                    subject: $lockedSubject,
                    before: $before,
                    after: ['status' => 'archived'],
                );

                return $lockedSubject->fresh();
            }, attempts: 3);
        });
    }

    public function restoreSubject(Organization $organization, Subject $subject, ?User $actor = null): Subject
    {
        return $this->tenantContext->run($organization, function () use ($organization, $subject, $actor): Subject {
            return DB::transaction(function () use ($organization, $subject, $actor): Subject {
                $this->lockOrganization($organization);
                $lockedSubject = Subject::withTrashed()
                    ->whereKey($subject->getKey())
                    ->where('organization_id', $organization->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $lockedSubject->restore();
                $lockedSubject->update(['is_active' => true]);
                $lockedSubject->refresh();

                $this->auditLogger->record(
                    action: 'subject.restored',
                    organization: $organization,
                    actor: $actor,
                    subject: $lockedSubject,
                    before: ['status' => 'archived'],
                    after: $this->subjectSnapshot($lockedSubject),
                );

                return $lockedSubject;
            }, attempts: 3);
        });
    }

    private function lockOrganization(Organization $organization): Organization
    {
        return Organization::query()
            ->whereKey($organization->getKey())
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

    private function lockComponent(Organization $organization, SubjectComponent $component): SubjectComponent
    {
        return SubjectComponent::query()
            ->whereKey($component->getKey())
            ->where('organization_id', $organization->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * @param  array<int, RoomType>  $roomTypes
     * @return array<int, int>
     */
    private function lockRoomTypeIds(Organization $organization, array $roomTypes): array
    {
        $ids = [];

        foreach ($roomTypes as $roomType) {
            $ids[] = RoomType::query()
                ->whereKey($roomType->getKey())
                ->where('organization_id', $organization->getKey())
                ->lockForUpdate()
                ->firstOrFail()
                ->getKey();
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<int, array{feature: Feature, minimum_quantity?: int|null}>  $features
     * @return array<int, array{feature_id: int, minimum_quantity: int|null}>
     */
    private function lockFeatureRequirements(Organization $organization, array $features): array
    {
        $requirements = [];

        foreach ($features as $featureRequirement) {
            $feature = $featureRequirement['feature'];
            $featureId = Feature::query()
                ->whereKey($feature->getKey())
                ->where('organization_id', $organization->getKey())
                ->lockForUpdate()
                ->firstOrFail()
                ->getKey();
            $requirements[$featureId] = [
                'feature_id' => $featureId,
                'minimum_quantity' => $featureRequirement['minimum_quantity'] ?? null,
            ];
        }

        return array_values($requirements);
    }

    private function assertLabel(string $value, string $field): void
    {
        if (trim($value) === '') {
            throw ValidationException::withMessages([$field => 'This value is required.']);
        }
    }

    private function assertUnits(?float $units): void
    {
        if ($units !== null && ($units < 0 || $units > 9999.99)) {
            throw ValidationException::withMessages(['units' => 'Subject units must be between zero and 9,999.99.']);
        }
    }

    private function assertSchedulingValues(
        int $weeklyMinutes,
        int $sessionsPerWeek,
        int $defaultDurationMinutes,
        ?int $minimumRoomCapacity,
    ): void {
        if ($weeklyMinutes < 1 || $sessionsPerWeek < 1 || $defaultDurationMinutes < 1) {
            throw ValidationException::withMessages(['component' => 'Component scheduling values must be positive.']);
        }

        if ($minimumRoomCapacity !== null && $minimumRoomCapacity < 1) {
            throw ValidationException::withMessages(['minimum_room_capacity' => 'Minimum room capacity must be positive when provided.']);
        }
    }

    /** @param array<int, array{feature: Feature, minimum_quantity?: int|null}> $features */
    private function assertFeatureRequirements(array $features): void
    {
        foreach ($features as $featureRequirement) {
            $minimumQuantity = $featureRequirement['minimum_quantity'] ?? null;

            if ($minimumQuantity !== null && $minimumQuantity < 1) {
                throw ValidationException::withMessages(['features' => 'Feature quantities must be positive when provided.']);
            }
        }
    }

    /**
     * @return array{id: string, code: string, name: string, units: float|null, is_active: bool}
     */
    private function subjectSnapshot(Subject $subject): array
    {
        return [
            'id' => $subject->public_id,
            'code' => $subject->code,
            'name' => $subject->name,
            'units' => $subject->units,
            'is_active' => $subject->is_active,
        ];
    }

    /**
     * @return array{kind: string, name: string, weekly_minutes: int, sessions_per_week: int, default_duration_minutes: int, minimum_room_capacity: int|null, delivery_mode: string}
     */
    private function componentSnapshot(SubjectComponent $component): array
    {
        return [
            'kind' => $component->kind->value,
            'name' => $component->name,
            'weekly_minutes' => $component->weekly_minutes,
            'sessions_per_week' => $component->sessions_per_week,
            'default_duration_minutes' => $component->default_duration_minutes,
            'minimum_room_capacity' => $component->minimum_room_capacity,
            'delivery_mode' => $component->delivery_mode->value,
        ];
    }

    /**
     * @return array{room_type_codes: list<string>, feature_codes: list<string>}
     */
    private function requirementsSnapshot(SubjectComponent $component): array
    {
        $roomTypeCodes = [];

        foreach ($component->roomTypes->sortBy('code') as $roomType) {
            $roomTypeCodes[] = $roomType->code;
        }

        $featureCodes = [];

        foreach ($component->features->sortBy('code') as $feature) {
            $featureCodes[] = $feature->code;
        }

        return [
            'room_type_codes' => $roomTypeCodes,
            'feature_codes' => $featureCodes,
        ];
    }
}
