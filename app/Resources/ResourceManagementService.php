<?php

namespace App\Resources;

use App\Audit\AuditLogger;
use App\Enums\DeliveryMode;
use App\Enums\ResourceType;
use App\Models\AcademicUnit;
use App\Models\Building;
use App\Models\Feature;
use App\Models\Organization;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\SchedulingResource;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ResourceManagementService
{
    public function __construct(
        private TenantContext $tenantContext,
        private AuditLogger $auditLogger,
    ) {}

    public function createRoomType(Organization $organization, string $code, string $name, bool $isSystem = false, ?User $actor = null): RoomType
    {
        $this->assertLabel($code, 'room_type_code');
        $this->assertLabel($name, 'room_type_name');

        return $this->tenantContext->run($organization, function () use ($organization, $code, $name, $isSystem, $actor): RoomType {
            return DB::transaction(function () use ($organization, $code, $name, $isSystem, $actor): RoomType {
                $this->lockOrganization($organization);

                $roomType = RoomType::query()->create([
                    'organization_id' => $organization->getKey(),
                    'code' => $code,
                    'name' => $name,
                    'is_system' => $isSystem,
                ]);

                $this->auditLogger->record(
                    action: 'room_type.created',
                    organization: $organization,
                    actor: $actor,
                    after: $this->roomTypeSnapshot($roomType),
                );

                return $roomType;
            }, attempts: 3);
        });
    }

    public function createFeature(Organization $organization, string $code, string $name, ?User $actor = null): Feature
    {
        $this->assertLabel($code, 'feature_code');
        $this->assertLabel($name, 'feature_name');

        return $this->tenantContext->run($organization, function () use ($organization, $code, $name, $actor): Feature {
            return DB::transaction(function () use ($organization, $code, $name, $actor): Feature {
                $this->lockOrganization($organization);

                $feature = Feature::query()->create([
                    'organization_id' => $organization->getKey(),
                    'code' => $code,
                    'name' => $name,
                ]);

                $this->auditLogger->record(
                    action: 'room_feature.created',
                    organization: $organization,
                    actor: $actor,
                    after: $this->featureSnapshot($feature),
                );

                return $feature;
            }, attempts: 3);
        });
    }

    public function createBuilding(
        Organization $organization,
        string $code,
        string $name,
        ?AcademicUnit $campus = null,
        ?User $actor = null,
    ): Building {
        $this->assertLabel($code, 'building_code');
        $this->assertLabel($name, 'building_name');

        return $this->tenantContext->run($organization, function () use ($organization, $code, $name, $campus, $actor): Building {
            return DB::transaction(function () use ($organization, $code, $name, $campus, $actor): Building {
                $this->lockOrganization($organization);
                $lockedCampus = $campus === null ? null : AcademicUnit::query()
                    ->whereKey($campus->getKey())
                    ->where('organization_id', $organization->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $building = Building::query()->create([
                    'organization_id' => $organization->getKey(),
                    'campus_academic_unit_id' => $lockedCampus?->getKey(),
                    'code' => $code,
                    'name' => $name,
                ]);

                $this->auditLogger->record(
                    action: 'building.created',
                    organization: $organization,
                    actor: $actor,
                    after: $this->buildingSnapshot($building, $lockedCampus),
                );

                return $building;
            }, attempts: 3);
        });
    }

    public function createRoom(
        Organization $organization,
        string $resourceName,
        string $code,
        string $name,
        RoomType $roomType,
        ?Building $building = null,
        ?int $capacity = null,
        DeliveryMode $deliveryMode = DeliveryMode::Physical,
        ?User $actor = null,
    ): Room {
        $this->assertLabel($resourceName, 'resource_name');
        $this->assertLabel($code, 'room_code');
        $this->assertLabel($name, 'room_name');
        $this->assertCapacity($capacity);

        return $this->tenantContext->run($organization, function () use ($organization, $resourceName, $code, $name, $roomType, $building, $capacity, $deliveryMode, $actor): Room {
            return DB::transaction(function () use ($organization, $resourceName, $code, $name, $roomType, $building, $capacity, $deliveryMode, $actor): Room {
                $this->lockOrganization($organization);
                $lockedRoomType = RoomType::query()
                    ->whereKey($roomType->getKey())
                    ->where('organization_id', $organization->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $buildingId = $building === null ? null : Building::query()
                    ->whereKey($building->getKey())
                    ->where('organization_id', $organization->getKey())
                    ->lockForUpdate()
                    ->firstOrFail()
                    ->getKey();
                $resource = SchedulingResource::query()->create([
                    'organization_id' => $organization->getKey(),
                    'type' => ResourceType::Room,
                    'name' => $resourceName,
                    'is_active' => true,
                ]);

                $room = Room::query()->create([
                    'organization_id' => $organization->getKey(),
                    'scheduling_resource_id' => $resource->getKey(),
                    'building_id' => $buildingId,
                    'room_type_id' => $lockedRoomType->getKey(),
                    'code' => $code,
                    'name' => $name,
                    'capacity' => $capacity,
                    'delivery_mode' => $deliveryMode,
                ]);

                $room = $room->fresh(['resource', 'building', 'roomType']);

                $this->auditLogger->record(
                    action: 'room.created',
                    organization: $organization,
                    actor: $actor,
                    subject: $room,
                    after: $this->roomSnapshot($room),
                );

                return $room;
            }, attempts: 3);
        });
    }

    public function attachFeature(
        Organization $organization,
        Room $room,
        Feature $feature,
        ?int $quantity = null,
        ?User $actor = null,
    ): Room {
        $this->assertCapacity($quantity);

        return $this->tenantContext->run($organization, function () use ($organization, $room, $feature, $quantity, $actor): Room {
            return DB::transaction(function () use ($organization, $room, $feature, $quantity, $actor): Room {
                $this->lockOrganization($organization);
                $lockedRoom = $this->lockRoom($organization, $room);
                $lockedFeature = Feature::query()
                    ->whereKey($feature->getKey())
                    ->where('organization_id', $organization->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $beforeQuantity = DB::table('room_features')
                    ->where('organization_id', $organization->getKey())
                    ->where('room_id', $lockedRoom->getKey())
                    ->where('feature_id', $lockedFeature->getKey())
                    ->value('quantity');

                DB::table('room_features')->updateOrInsert(
                    [
                        'organization_id' => $organization->getKey(),
                        'room_id' => $lockedRoom->getKey(),
                        'feature_id' => $lockedFeature->getKey(),
                    ],
                    ['quantity' => $quantity],
                );

                $this->auditLogger->record(
                    action: 'room.feature_attached',
                    organization: $organization,
                    actor: $actor,
                    subject: $lockedRoom,
                    before: $beforeQuantity === null ? null : ['feature_code' => $lockedFeature->code, 'quantity' => (int) $beforeQuantity],
                    after: ['feature_code' => $lockedFeature->code, 'quantity' => $quantity],
                );

                return $lockedRoom->fresh(['features']);
            }, attempts: 3);
        });
    }

    public function removeFeature(Organization $organization, Room $room, Feature $feature, ?User $actor = null): Room
    {
        return $this->tenantContext->run($organization, function () use ($organization, $room, $feature, $actor): Room {
            return DB::transaction(function () use ($organization, $room, $feature, $actor): Room {
                $this->lockOrganization($organization);
                $lockedRoom = $this->lockRoom($organization, $room);
                $lockedFeature = Feature::query()
                    ->whereKey($feature->getKey())
                    ->where('organization_id', $organization->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $deleted = DB::table('room_features')
                    ->where('organization_id', $organization->getKey())
                    ->where('room_id', $lockedRoom->getKey())
                    ->where('feature_id', $lockedFeature->getKey())
                    ->delete();

                if ($deleted === 1) {
                    $this->auditLogger->record(
                        action: 'room.feature_removed',
                        organization: $organization,
                        actor: $actor,
                        subject: $lockedRoom,
                        before: ['feature_code' => $lockedFeature->code],
                    );
                }

                return $lockedRoom->fresh(['features']);
            }, attempts: 3);
        });
    }

    public function archiveRoom(Organization $organization, Room $room, ?User $actor = null): Room
    {
        return $this->tenantContext->run($organization, function () use ($organization, $room, $actor): Room {
            return DB::transaction(function () use ($organization, $room, $actor): Room {
                $this->lockOrganization($organization);
                $lockedRoom = $this->lockRoom($organization, $room);
                $resource = SchedulingResource::query()
                    ->whereKey($lockedRoom->scheduling_resource_id)
                    ->where('organization_id', $organization->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $lockedRoom->delete();
                $resource->update(['is_active' => false]);

                $this->auditLogger->record(
                    action: 'room.archived',
                    organization: $organization,
                    actor: $actor,
                    subject: $lockedRoom,
                    before: ['resource_id' => $resource->public_id, 'status' => 'active'],
                    after: ['status' => 'archived'],
                );

                return $lockedRoom->fresh(['resource']);
            }, attempts: 3);
        });
    }

    public function restoreRoom(Organization $organization, Room $room, ?User $actor = null): Room
    {
        return $this->tenantContext->run($organization, function () use ($organization, $room, $actor): Room {
            return DB::transaction(function () use ($organization, $room, $actor): Room {
                $this->lockOrganization($organization);
                $lockedRoom = Room::withTrashed()
                    ->whereKey($room->getKey())
                    ->where('organization_id', $organization->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $resource = SchedulingResource::query()
                    ->whereKey($lockedRoom->scheduling_resource_id)
                    ->where('organization_id', $organization->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $lockedRoom->restore();
                $resource->update(['is_active' => true]);

                $this->auditLogger->record(
                    action: 'room.restored',
                    organization: $organization,
                    actor: $actor,
                    subject: $lockedRoom,
                    before: ['status' => 'archived'],
                    after: ['resource_id' => $resource->public_id, 'status' => 'active'],
                );

                return $lockedRoom->fresh(['resource']);
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

    private function lockRoom(Organization $organization, Room $room): Room
    {
        return Room::query()
            ->whereKey($room->getKey())
            ->where('organization_id', $organization->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertLabel(string $value, string $field): void
    {
        if (trim($value) === '') {
            throw ValidationException::withMessages([$field => 'This value is required.']);
        }
    }

    private function assertCapacity(?int $capacity): void
    {
        if ($capacity !== null && $capacity < 1) {
            throw ValidationException::withMessages(['capacity' => 'Capacity and feature quantities must be positive when provided.']);
        }
    }

    /**
     * @return array{code: string, name: string, is_system: bool}
     */
    private function roomTypeSnapshot(RoomType $roomType): array
    {
        return [
            'code' => $roomType->code,
            'name' => $roomType->name,
            'is_system' => $roomType->is_system,
        ];
    }

    /**
     * @return array{code: string, name: string}
     */
    private function featureSnapshot(Feature $feature): array
    {
        return ['code' => $feature->code, 'name' => $feature->name];
    }

    /**
     * @return array{code: string, name: string, campus_id: string|null}
     */
    private function buildingSnapshot(Building $building, ?AcademicUnit $campus): array
    {
        return [
            'code' => $building->code,
            'name' => $building->name,
            'campus_id' => $campus?->public_id,
        ];
    }

    /**
     * @return array{id: string, resource_id: string, code: string, name: string, building_code: string|null, room_type_code: string, capacity: int|null, delivery_mode: string}
     */
    private function roomSnapshot(Room $room): array
    {
        return [
            'id' => $room->public_id,
            'resource_id' => $room->resource->public_id,
            'code' => $room->code,
            'name' => $room->name,
            'building_code' => $room->building?->code,
            'room_type_code' => $room->roomType->code,
            'capacity' => $room->capacity,
            'delivery_mode' => $room->delivery_mode->value,
        ];
    }
}
