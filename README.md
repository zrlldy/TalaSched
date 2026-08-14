
TalaSched is a subscription-based, organization-oriented timetable platform for institutions ranging from preschool to university. It is implemented as a Laravel 13 modular monolith with an Inertia Vue frontend, PostgreSQL, Redis, Laravel queues, S3-compatible storage, and PhpSpreadsheet.

The architecture uses one canonical schedule source to derive teacher, student-group, room, academic-unit, and organization-wide views. The first release supports manual scheduling, recurring weekly schedules with dated exceptions, conflict validation, immutable timetable versions, configurable approvals, controlled Excel templates, and provider-neutral subscription entitlements. Automatic scheduling is isolated behind a port for a later Laravel or OR-Tools implementation.
