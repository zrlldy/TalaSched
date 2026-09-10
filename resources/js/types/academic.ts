export type AcademicYearOption = {
    id: string;
    name: string;
    starts_on: string;
    ends_on: string;
    status: 'draft' | 'active' | 'closed';
};

export type AcademicCalendar = {
    weekday: number;
    starts_at_minute: number;
    ends_at_minute: number;
};

export type CalendarException = {
    date: string;
    kind: string;
    name: string;
    starts_at_minute: number | null;
    ends_at_minute: number | null;
};

export type AcademicPeriod = {
    id: string;
    name: string;
    kind: string;
    kind_label: string;
    sequence: number;
    starts_on: string;
    ends_on: string;
    calendars: AcademicCalendar[];
    exceptions: CalendarException[];
};

export type AcademicYear = AcademicYearOption & { periods: AcademicPeriod[] };
export type AcademicKindOption = { value: string; label: string };

export type AcademicUnitOption = {
    id: string;
    name: string;
    type_name: string;
};

export type StudentGroup = {
    id: string;
    code: string;
    name: string;
    expected_headcount: number;
    academic_year_id: string;
    year_starts_on: string;
    year_ends_on: string;
    year_status: 'draft' | 'active' | 'closed';
    active_from: string | null;
    active_until: string | null;
    academic_unit: { id: string; name: string };
    periods: { id: string; name: string; enrolled: boolean }[];
};
