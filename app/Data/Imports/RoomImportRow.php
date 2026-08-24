<?php

namespace App\Data\Imports;

use App\Enums\DeliveryMode;

final readonly class RoomImportRow
{
    public function __construct(
        public string $resourceName,
        public string $code,
        public string $name,
        public string $roomTypeCode,
        public ?string $buildingCode,
        public ?int $capacity,
        public DeliveryMode $deliveryMode,
    ) {}

    /** @return list<string> */
    public static function columns(): array
    {
        return [
            'resource_name',
            'code',
            'name',
            'room_type_code',
            'building_code',
            'capacity',
            'delivery_mode',
        ];
    }

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): self
    {
        $deliveryMode = ImportRowValue::requiredString($row, 'delivery_mode');

        return new self(
            resourceName: ImportRowValue::requiredString($row, 'resource_name'),
            code: ImportRowValue::requiredString($row, 'code'),
            name: ImportRowValue::requiredString($row, 'name'),
            roomTypeCode: ImportRowValue::requiredString($row, 'room_type_code'),
            buildingCode: ImportRowValue::optionalString($row, 'building_code'),
            capacity: ImportRowValue::optionalInteger($row, 'capacity'),
            deliveryMode: ImportRowValue::enumValue(
                $deliveryMode,
                'delivery_mode',
                fn (string $value): ?DeliveryMode => DeliveryMode::tryFrom($value),
            ),
        );
    }
}
