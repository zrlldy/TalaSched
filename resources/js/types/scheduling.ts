export type ScheduleIssueSeverity = 'hard' | 'soft';

export type TimetableVersionStatus =
    | 'draft'
    | 'in_review'
    | 'changes_requested'
    | 'approved'
    | 'published'
    | 'superseded';

export type ScheduleIssueResource = {
    id: string | null;
    name: string | null;
};

export type ScheduleIssue = {
    code: string;
    severity: ScheduleIssueSeverity;
    field: string;
    rule_code: string;
    message: string;
    details: Record<string, unknown>;
    resource?: ScheduleIssueResource;
    conflicting_entry_id?: string | null;
    acknowledgement_required?: boolean;
    configuration_id?: string | null;
};
