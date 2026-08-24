---
paths:
  - 'app/Academic/**'
---

# Academic

## Mutate academic hierarchy through the service
Academic hierarchy create, move, archive, and root queries must run inside TenantContext. Mutations use retried transactions and ordered row locks, validate configured type edges, and maintain academic_unit_closure rows; archive refuses active children or student groups.

## Academic presets are additive and editable
Academic hierarchy presets provision organization-local unit types, allowed edges, and ordinary sample units with stable codes. Applying a preset is idempotent and must preserve existing records with those codes so administrators can customize the resulting hierarchy.

## Academic year lifecycle is draft-first
Create academic years in draft status. Only draft years accept new periods; periods within a year must not overlap. Activation requires at least one period with contiguous sequence numbers starting at one and rejects overlapping active years. Closing is allowed only from active and is irreversible through this service.

## Calendar exceptions use one date-level override
AcademicCalendarService stores one operating-hours row per period and weekday and one idempotent exception per period and date. Holiday and blocked exceptions cover the full day; teaching exceptions may omit times to inherit normal hours or provide a positive explicit window. Calendar dates must stay within their academic period and closed years reject changes.

## Student-group participation is year-scoped
Student groups may be assigned only to organization-local academic units and enrolled only in periods from their academic year. Optional active dates must fit the academic year and overlap an enrolled period; closed academic years reject group date, unit, and period membership changes.
