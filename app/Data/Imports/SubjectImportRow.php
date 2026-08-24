<?php

namespace App\Data\Imports;

final readonly class SubjectImportRow
{
    public function __construct(
        public string $code,
        public string $name,
        public ?float $units,
        public ?string $description,
    ) {}

    /** @return list<string> */
    public static function columns(): array
    {
        return ['code', 'name', 'units', 'description'];
    }

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): self
    {
        return new self(
            code: ImportRowValue::requiredString($row, 'code'),
            name: ImportRowValue::requiredString($row, 'name'),
            units: ImportRowValue::optionalDecimal($row, 'units'),
            description: ImportRowValue::optionalString($row, 'description'),
        );
    }
}
