<?php

use App\Models\ScheduleEntry;
use App\Models\SchedulingResource;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('every tenant parent reference has a composite tenant-safe foreign key', function () {
    $tables = collect(Schema::getTables())->pluck('name');

    foreach ($tables as $table) {
        if (! Schema::hasColumn($table, 'organization_id')) {
            continue;
        }

        $foreignKeys = collect(Schema::getForeignKeys($table));

        foreach ($foreignKeys as $foreignKey) {
            $parentTable = $foreignKey['foreign_table'];

            if ($foreignKey['columns'] === ['organization_id']
                || $foreignKey['foreign_columns'] !== ['id']
                || ! Schema::hasColumn($parentTable, 'organization_id')) {
                continue;
            }

            $tenantForeignKeyExists = $foreignKeys->contains(fn (array $candidate): bool => $candidate['columns'] === ['organization_id', $foreignKey['columns'][0]]
                && $candidate['foreign_table'] === $parentTable
                && $candidate['foreign_columns'] === ['organization_id', 'id']);

            expect($tenantForeignKeyExists)
                ->toBeTrue("{$table}.{$foreignKey['columns'][0]} is not tenant-safe.");
        }
    }
});

test('tenant-owned pivot tables carry explicit organization ownership', function (string $table) {
    expect(Schema::hasColumn($table, 'organization_id'))->toBeTrue();
})->with([
    'role permissions' => 'role_permissions',
    'student group periods' => 'student_group_periods',
    'room features' => 'room_features',
    'subject component room types' => 'subject_component_room_types',
    'subject component features' => 'subject_component_features',
    'offering component features' => 'offering_component_features',
    'approval step roles' => 'approval_step_roles',
]);

test('the database rejects cross-organization schedule resource assignments', function () {
    $entry = ScheduleEntry::factory()->create();
    $otherResource = SchedulingResource::factory()->create();

    expect(fn () => DB::table('schedule_entry_resources')->insert([
        'organization_id' => $entry->organization_id,
        'schedule_entry_id' => $entry->id,
        'scheduling_resource_id' => $otherResource->id,
        'role' => 'room',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});
