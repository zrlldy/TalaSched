export type ScheduleIssueSeverity = 'hard' | 'soft';

export type ScheduleOfferingOption = {
    id: string;
    name: string;
    group: { id: string; name: string };
    instructors: { id: string; name: string }[];
    duration_minutes: number;
    delivery_mode: string;
};

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

export type TimetableViewScope =
    'organization' | 'teacher' | 'student_group' | 'room' | 'unit';

export type TimetableViewResource = {
    id: string;
    name: string;
    type: string;
    role: string;
};

export type TimetableViewException = {
    id: string;
    date: string;
    action: 'cancelled' | 'rescheduled' | 'replaced';
    starts_at_minute: number | null;
    ends_at_minute: number | null;
    reason: string | null;
    resources: TimetableViewResource[];
};

export type TimetableViewEntry = {
    id: string;
    logical_id: string;
    weekday: number;
    date: string | null;
    status: 'scheduled' | 'cancelled' | 'rescheduled' | 'replaced';
    starts_at_minute: number | null;
    ends_at_minute: number | null;
    delivery_mode: string;
    notes: string | null;
    lock_version: number;
    offering: {
        component: { id: string; name: string; kind: string };
        offering: {
            id: string;
            code: string | null;
            expected_enrollment: number;
        };
        subject: { id: string; code: string | null; name: string };
        student_group: {
            id: string;
            code: string;
            name: string;
            academic_unit: { id: string; name: string; code: string | null };
        };
        owning_unit: { id: string; name: string; code: string | null } | null;
    };
    resources: TimetableViewResource[];
    exceptions: TimetableViewException[];
};

export type TimetableView = {
    scope: TimetableViewScope;
    date: string | null;
    context: {
        organization: {
            id: string;
            name: string;
            slug: string;
            timezone: string;
        };
        timetable: {
            id: string;
            name: string;
            timezone: string;
            scheduling_granularity: number;
        };
        period: {
            id: string;
            name: string;
            starts_on: string;
            ends_on: string;
        };
        version: {
            id: string;
            number: number;
            status: TimetableVersionStatus;
            lock_version: number;
        };
    };
    entries: TimetableViewEntry[];
};
