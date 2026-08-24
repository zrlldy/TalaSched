<?php

use App\Models\Organization;
use App\Scheduling\SchedulingAdvisoryLock;

test('advisory lock names are stable and sort affected resources', function (): void {
    $organization = new Organization;
    $organization->setRawAttributes(['id' => 17]);

    expect((new SchedulingAdvisoryLock)->lockNames($organization, 23, [9, 3, 9]))->toBe([
        'talasched:organization:17',
        'talasched:organization:17:timetable-version:23',
        'talasched:organization:17:resource:3',
        'talasched:organization:17:resource:9',
    ]);
});
