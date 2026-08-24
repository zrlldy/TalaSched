<?php

use App\Enums\DeliveryMode;
use App\Enums\ResourceType;
use App\Models\AcademicUnit;
use App\Models\AcademicUnitType;
use App\Models\Feature;
use App\Models\Organization;
use App\Models\Room;
use App\Models\RoomType;
use App\Resources\ResourceManagementService;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

test('resource service manages campuses, rooms, room types, features, and archival state', function (): void {
    $organization = Organization::factory()->create();
    $unitType = AcademicUnitType::factory()->forOrganization($organization)->create();
    $campus = AcademicUnit::factory()->forType($unitType)->create(['name' => 'Main Campus']);
    $service = app(ResourceManagementService::class);

    $roomType = $service->createRoomType($organization, 'LAB', 'Laboratory');
    $feature = $service->createFeature($organization, 'PROJECTOR', 'Projector');
    $building = $service->createBuilding($organization, 'SCI', 'Science Building', $campus);
    $room = $service->createRoom(
        $organization,
        'Science Lab 1',
        'SCI-101',
        'Science Laboratory 1',
        $roomType,
        $building,
        32,
        DeliveryMode::Hybrid,
    );
    $room = $service->attachFeature($organization, $room, $feature, 2);
    $featureQuantity = DB::table('room_features')
        ->where('room_id', $room->getKey())
        ->where('feature_id', $feature->getKey())
        ->value('quantity');

    expect($room->resource->type)->toBe(ResourceType::Room)
        ->and($room->resource->name)->toBe('Science Lab 1')
        ->and($room->building->is($building))->toBeTrue()
        ->and($room->building->campus->is($campus))->toBeTrue()
        ->and($room->roomType->is($roomType))->toBeTrue()
        ->and($room->delivery_mode)->toBe(DeliveryMode::Hybrid)
        ->and($room->capacity)->toBe(32)
        ->and($room->features->first()->is($feature))->toBeTrue()
        ->and($featureQuantity)->toBe(2);

    $archivedRoom = $service->archiveRoom($organization, $room);

    expect($archivedRoom->trashed())->toBeTrue()
        ->and($archivedRoom->resource->is_active)->toBeFalse()
        ->and(Room::query()->whereKey($room->getKey())->exists())->toBeFalse();

    $restoredRoom = $service->restoreRoom($organization, $archivedRoom);

    expect($restoredRoom->trashed())->toBeFalse()
        ->and($restoredRoom->resource->is_active)->toBeTrue()
        ->and(Room::query()->whereKey($room->getKey())->exists())->toBeTrue()
        ->and(app(TenantContext::class)->organization())->toBeNull();

    $service->removeFeature($organization, $restoredRoom, $feature);

    expect(DB::table('room_features')->where('room_id', $room->getKey())->exists())->toBeFalse();
});

test('resource service rejects invalid room data and foreign parent records', function (): void {
    $organization = Organization::factory()->create();
    $foreignOrganization = Organization::factory()->create();
    $service = app(ResourceManagementService::class);
    $roomType = $service->createRoomType($organization, 'CLASS', 'Classroom');
    $foreignRoomType = RoomType::factory()->forOrganization($foreignOrganization)->create();
    $feature = $service->createFeature($organization, 'BOARD', 'Whiteboard');
    $foreignFeature =
        Feature::factory()->forOrganization($foreignOrganization)->create();

    expect(fn () => $service->createRoom($organization, 'Room 1', 'R1', 'Room 1', $roomType, null, 0))
        ->toThrow(ValidationException::class, 'positive')
        ->and(fn () => $service->createRoom($organization, 'Room 1', 'R1', 'Room 1', $foreignRoomType))
        ->toThrow(ModelNotFoundException::class)
        ->and(fn () => $service->createFeature($organization, '', 'Feature'))
        ->toThrow(ValidationException::class, 'required');

    $room = $service->createRoom($organization, 'Room 1', 'R1', 'Room 1', $roomType);

    expect(fn () => $service->attachFeature($organization, $room, $foreignFeature))
        ->toThrow(ModelNotFoundException::class)
        ->and($feature->organization_id)->toBe($organization->getKey());
});
