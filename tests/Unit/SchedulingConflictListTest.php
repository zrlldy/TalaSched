<?php

test('the scheduling conflict list follows the structured issue contract', function () {
    $types = file_get_contents(dirname(__DIR__, 2).'/resources/js/types/scheduling.ts');
    $component = file_get_contents(dirname(__DIR__, 2).'/resources/js/components/scheduling/SchedulingConflictList.vue');

    expect($types)
        ->toContain("export type ScheduleIssueSeverity = 'hard' | 'soft';")
        ->toContain('code: string;')
        ->toContain('severity: ScheduleIssueSeverity;')
        ->toContain('field: string;')
        ->toContain('rule_code: string;')
        ->toContain('message: string;')
        ->toContain('details: Record<string, unknown>;')
        ->toContain('resource?: ScheduleIssueResource;')
        ->toContain('conflicting_entry_id?: string | null;')
        ->toContain('acknowledgement_required?: boolean;');

    expect($component)
        ->toContain('data-slot="scheduling-conflict-list"')
        ->toContain("resource_overlap: 'time'")
        ->toContain("resource_unavailable: 'resource'")
        ->toContain("room_capacity: 'requirement'")
        ->toContain("faculty_daily_load: 'workload'")
        ->toContain("hasHardIssues ? 'alert' : 'status'")
        ->toContain("hasHardIssues ? 'assertive' : 'polite'")
        ->toContain("hasHardIssues ? 'Save blocked' : 'Review'")
        ->toContain("issue.severity === 'hard'")
        ->toContain("'Blocking'")
        ->toContain("'Warning'")
        ->toContain('<slot name="issue-action" :issue="issue" />');
});
