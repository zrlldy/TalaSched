<?php

use App\Enums\ResourceType;
use App\Models\Organization;
use App\Models\SchedulingResource;
use App\Scheduling\ScheduleDatabaseConflictTranslator;
use App\Scheduling\ScheduleEntryData;
use Illuminate\Database\QueryException;
use PDOException;

test('postgres exclusion violations become structured resource conflicts', function (): void {
    $organization = Organization::factory()->create();
    $resource = SchedulingResource::create([
        'organization_id' => $organization->getKey(),
        'type' => ResourceType::Room,
        'name' => 'Room 301',
    ]);
    $previous = new PDOException('conflict');
    $previous->errorInfo = ['23P01', null, 'schedule_resource_no_overlap'];
    $exception = new QueryException('pgsql', 'insert into schedule_reservations', [], $previous);
    $data = new ScheduleEntryData(1, 1, 1, 480, 570, [
        ['resource_id' => $resource->getKey(), 'role' => 'room'],
    ]);

    $issues = app(ScheduleDatabaseConflictTranslator::class)->translate($exception, $organization, $data);

    expect($issues)->toHaveCount(1)
        ->and($issues[0]['code'])->toBe('resource_overlap')
        ->and($issues[0]['resource'])->toBe([
            'id' => $resource->public_id,
            'name' => 'Room 301',
        ])
        ->and($issues[0]['details'])->toBe([
            'source' => 'database',
            'constraint' => 'schedule_resource_no_overlap',
        ]);
});

test('unrelated database uniqueness violations are not translated', function (): void {
    $organization = Organization::factory()->create();
    $previous = new PDOException('duplicate');
    $previous->errorInfo = ['23505', null, 'organizations_slug_unique'];
    $exception = new QueryException('pgsql', 'insert into organizations', [], $previous);
    $data = new ScheduleEntryData(1, 1, 1, 480, 570, []);

    expect(app(ScheduleDatabaseConflictTranslator::class)->translate($exception, $organization, $data))
        ->toBeNull();
});
