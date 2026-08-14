<?php

test('timetable version status mirrors every backend status with a readable label', function () {
    $component = file_get_contents(dirname(__DIR__, 2).'/resources/js/components/scheduling/TimetableVersionStatus.vue');
    $types = file_get_contents(dirname(__DIR__, 2).'/resources/js/types/scheduling.ts');
    $backendEnum = file_get_contents(dirname(__DIR__, 2).'/app/Enums/TimetableVersionStatus.php');

    preg_match_all("/case \\w+ = '([^']+)';/", $backendEnum, $backendStatuses);
    preg_match('/export type TimetableVersionStatus =(.+?);/s', $types, $frontendType);
    preg_match_all("/'([^']+)'/", $frontendType[1], $frontendStatuses);

    expect($frontendStatuses[1])->toBe($backendStatuses[1]);

    expect($component)
        ->toContain("label: 'Draft'")
        ->toContain("label: 'In review'")
        ->toContain("label: 'Changes requested'")
        ->toContain("label: 'Approved'")
        ->toContain("label: 'Published'")
        ->toContain("label: 'Superseded'");
});

test('timetable version status uses an accessible visible stamp instead of status-derived actions', function () {
    $component = file_get_contents(dirname(__DIR__, 2).'/resources/js/components/scheduling/TimetableVersionStatus.vue');

    expect($component)
        ->toContain('data-slot="timetable-version-status"')
        ->toContain(':data-status="status"')
        ->toContain('aria-hidden="true"')
        ->toContain('{{ presentation.label }}')
        ->toContain('font-schedule')
        ->toContain('border-schedule/30 bg-schedule/10')
        ->toContain('border-warning/40 bg-warning/12')
        ->toContain('border-conflict/35 bg-conflict/10')
        ->toContain('border-available/35 bg-available/12')
        ->toContain('bg-available text-available-foreground')
        ->not->toContain('isEditable')
        ->not->toContain('<button');
});
