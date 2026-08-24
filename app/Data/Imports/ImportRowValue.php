<?php

namespace App\Data\Imports;

use InvalidArgumentException;

final class ImportRowValue
{
    /** @param array<string, mixed> $row */
    public static function requiredString(array $row, string $key): string
    {
        $value = self::optionalString($row, $key);

        if ($value === null) {
            throw new InvalidArgumentException("The {$key} import column is required.");
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    public static function optionalString(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            throw new InvalidArgumentException("The {$key} import column must be text.");
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** @param array<string, mixed> $row */
    public static function optionalInteger(array $row, string $key): ?int
    {
        $value = $row[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        throw new InvalidArgumentException("The {$key} import column must be an integer.");
    }

    /** @param array<string, mixed> $row */
    public static function optionalDecimal(array $row, string $key): ?float
    {
        $value = $row[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric(trim($value)))) {
            return (float) $value;
        }

        throw new InvalidArgumentException("The {$key} import column must be numeric.");
    }

    public static function enumValue(?string $value, string $key, callable $resolve): mixed
    {
        if ($value === null) {
            return null;
        }

        $resolved = $resolve($value);

        if ($resolved === null) {
            throw new InvalidArgumentException("The {$key} import column contains an unsupported value.");
        }

        return $resolved;
    }
}
