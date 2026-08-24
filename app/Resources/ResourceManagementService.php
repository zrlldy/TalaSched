<?php

namespace App\Resources;

use App\Enums\DeliveryMode;
use App\Enums\ResourceType;
use App\Models\AcademicUnit;
use App\Models\Building;
use App\Models\Feature;
use App\Models\Organization;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\SchedulingResource;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ResourceManagementService
{
    public function __construct(private TenantContext $tenantContext) {}

    public function createRoomType(Organization $organization, string $code, string $name, bool $isSystem = false): RoomType
    {
        $this->assertLabel($code, 'room_type_code');
        $this->assertLabel($name, 'room_type_name');

        return $this->tenantContext->run($organization, function () use ($organization, $code, $name, $isSystem): RoomType {
            return DB::transaction(function () use ($organization, $code, $name, $isSystem): RoomType {
                $this->lockOrganization($organization);

                return RoomType::query()->create([
                    'organization_id' => $organization->getKey(),
                    'code' => $code,
                    'name' => $name,
                    'is_system' => $isSystem,
                ]);
            }, attempts: 3);
        });
    }

    public function createFeature(Organization $organization, string $code, string $name): Feature
    {
        $this->assertLabel($code, 'feature_code');
        $this->assertLabel($name, 'feature_name');

        return $this->tenantContext->run($organization, function () use ($organization, $code, $name): Feature {
            return DB::transaction(function () use ($organization, $code, $name): Feature {
                $this->lockOrganization($organization);

                return Feature::query()->create([
                    'organization_id' => $organization->getKey(),
                    'code' => $code,
                    'name' => $name,
                ]);
            }, attempts: 3);
        });
    }

    public function createBuilding(
        Organization $organization,
        string $code,
        string $name,
        ?AcademicUnit $campus = null,
    ): Building {
        $this->assertLabel($code, 'building_code');
        $this->assertLabel($name, 'building_name');

        return $this->tenantContext->run($organization, function () use ($organization, $code, $name, $campus): Building {
            return DB::transaction(function () use ($organization, $code, $name, $campus): Building {
                $this->lockOrganization($organization);
                $campusId = $campus === null ? null : AcademicUnit::query()
                    ->whereKey($campus->getKey())
                    ->where('organization_id', $organization->getKey())
                    ->lockForUpdate()
                    ->firstOrFail()
                    ->getKey();

                return Building::query()->create([
                    'organization_id' => $organization->getKey(),
                    'campus_academic_unit_id' => $campusId,
                    'code' => $code,
                    'name' => $name,
                ]);
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
    ): Room {
        $this->assertLabel($resourceName, 'resource_name');
        $this->assertLabel($code, 'room_code');
        $this->assertLabel($name, 'room_name');
        $this->assertCapacity($capacity);

        return $this->tenantContext->run($organization, function () use ($organization, $resourceName, $code, $name, $roomType, $building, $capacity, $deliveryMode): Room {
            return DB::transaction(function () use ($organization, $resourceName, $code, $name, $roomType, $building, $capacity, $deliveryMode): Room {
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

                return $room->fresh(['resource', 'building', 'roomType']);
            }, attempts: 3);
        });
    }

    public function attachFeature(
        Organization $organization,
        Room $room,
        Feature $feature,
        ?int $quantity = null,
    ): Room {
        $this->assertCapacity($quantity);

        return $this->tenantContext->run($organization, function () use ($organization, $room, $feature, $quantity): Room {
            return DB::transaction(function () use ($organization, $room, $feature, $quantity): Room {
                $this->lockOrganization($organization);
                $lockedRoom = $this->lockRoom($organization, $room);
                $lockedFeature = Feature::query()
                    ->whereKey($feature->getKey())
                    ->where('organization_id', $organization->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                DB::table('room_features')->updateOrInsert(
                    [
                        'organization_id' => $organization->getKey(),
                        'room_id' => $lockedRoom->getKey(),
                        'feature_id' => $lockedFeature->getKey(),
                    ],
                    ['quantity' => $quantity],
                );

                return $lockedRoom->fresh(['features']);
            }, attempts: 3);
        });
    }

    public function removeFeature(Organization $organization, Room $room, Feature $feature): Room
    {
        return $this->tenantContext->run($organization, function () use ($organization, $room, $feature): Room {
            return DB::transaction(function () use ($organization, $room, $feature): Room {
                $this->lockOrganization($organization);
                $lockedRoom = $this->lockRoom($organization, $room);

                DB::table('room_features')
                    ->where('organization_id', $organization->getKey())
                    ->where('room_id', $lockedRoom->getKey())
                    ->where('feature_id', $feature->getKey())
                    ->delete();

                return $lockedRoom->fresh(['features']);
            }, attempts: 3);
        });
    }

    public function archiveRoom(Organization $organization, Room $room): Room
    {
        return $this->tenantContext->run($organization, function () use ($organization, $room): Room {
            return DB::transaction(function () use ($organization, $room): Room {
                $this->lockOrganization($organization);
                $lockedRoom = $this->lockRoom($organization, $room);
                $resource = SchedulingResource::query()
                    ->whereKey($lockedRoom->scheduling_resource_id)
                    ->where('organization_id', $organization->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $lockedRoom->delete();
                $resource->update(['is_active' => false]);

                return $lockedRoom->fresh(['resource']);
            }, attempts: 3);
        });
    }

    public function restoreRoom(Organization $organization, Room $room): Room
    {
        return $this->tenantContext->run($organization, function () use ($organization, $room): Room {
            return DB::transaction(function () use ($organization, $room): Room {
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
}
