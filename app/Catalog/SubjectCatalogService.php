<?php

namespace App\Catalog;

use App\Enums\DeliveryMode;
use App\Enums\SubjectComponentKind;
use App\Models\Feature;
use App\Models\Organization;
use App\Models\RoomType;
use App\Models\Subject;
use App\Models\SubjectComponent;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubjectCatalogService
{
    public function __construct(private TenantContext $tenantContext) {}

    public function createSubject(
        Organization $organization,
        string $code,
        string $name,
        ?float $units = null,
        ?string $description = null,
    ): Subject {
        $this->assertLabel($code, 'subject_code');
        $this->assertLabel($name, 'subject_name');
        $this->assertUnits($units);

        return $this->tenantContext->run($organization, function () use ($organization, $code, $name, $units, $description): Subject {
            return DB::transaction(function () use ($organization, $code, $name, $units, $description): Subject {
                $this->lockOrganization($organization);

                return Subject::query()->create([
                    'organization_id' => $organization->getKey(),
                    'code' => $code,
                    'name' => $name,
                    'units' => $units,
                    'description' => $description,
                    'is_active' => true,
                ]);
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
    ): Subject {
        $this->assertLabel($name, 'subject_name');
        $this->assertUnits($units);

        return $this->tenantContext->run($organization, function () use ($organization, $subject, $name, $units, $description, $isActive): Subject {
            return DB::transaction(function () use ($organization, $subject, $name, $units, $description, $isActive): Subject {
                $this->lockOrganization($organization);
                $lockedSubject = $this->lockSubject($organization, $subject);
                $lockedSubject->update([
                    'name' => $name,
                    'units' => $units,
                    'description' => $description,
                    'is_active' => $isActive,
                ]);

                return $lockedSubject->fresh();
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
    ): SubjectComponent {
        $this->assertLabel($name, 'component_name');
        $this->assertSchedulingValues($weeklyMinutes, $sessionsPerWeek, $defaultDurationMinutes, $minimumRoomCapacity);

        return $this->tenantContext->run($organization, function () use ($organization, $subject, $kind, $name, $weeklyMinutes, $sessionsPerWeek, $defaultDurationMinutes, $minimumRoomCapacity, $deliveryMode): SubjectComponent {
            return DB::transaction(function () use ($organization, $subject, $kind, $name, $weeklyMinutes, $sessionsPerWeek, $defaultDurationMinutes, $minimumRoomCapacity, $deliveryMode): SubjectComponent {
                $this->lockOrganization($organization);
                $lockedSubject = $this->lockSubject($organization, $subject);

                return SubjectComponent::query()->create([
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
    ): SubjectComponent {
        $this->assertFeatureRequirements($features);

        return $this->tenantContext->run($organization, function () use ($organization, $component, $roomTypes, $features): SubjectComponent {
            return DB::transaction(function () use ($organization, $component, $roomTypes, $features): SubjectComponent {
                $this->lockOrganization($organization);
                $lockedComponent = $this->lockComponent($organization, $component);
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

                return $lockedComponent->fresh(['roomTypes', 'features']);
            }, attempts: 3);
        });
    }

    public function archiveSubject(Organization $organization, Subject $subject): Subject
    {
        return $this->tenantContext->run($organization, function () use ($organization, $subject): Subject {
            return DB::transaction(function () use ($organization, $subject): Subject {
                $this->lockOrganization($organization);
                $lockedSubject = $this->lockSubject($organization, $subject);
                $lockedSubject->update(['is_active' => false]);
                $lockedSubject->delete();

                return $lockedSubject->fresh();
            }, attempts: 3);
        });
    }

    public function restoreSubject(Organization $organization, Subject $subject): Subject
    {
        return $this->tenantContext->run($organization, function () use ($organization, $subject): Subject {
            return DB::transaction(function () use ($organization, $subject): Subject {
                $this->lockOrganization($organization);
                $lockedSubject = Subject::withTrashed()
                    ->whereKey($subject->getKey())
                    ->where('organization_id', $organization->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $lockedSubject->restore();
                $lockedSubject->update(['is_active' => true]);

                return $lockedSubject->fresh();
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
}
