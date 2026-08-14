# Cross-Layer Architecture Review

This review reconciles the [system architecture](ARCHITECTURE.md), [backend design](../backend/BACKEND_DESIGN.md), [frontend architecture](../frontend/FRONTEND_ARCHITECTURE.md), and current repository.

## Contract map

| User journey | Frontend surface | Backend boundary | Authoritative data/invariant | Current state |
|---|---|---|---|---|
| Switch organization | Organization switcher | Membership middleware and switch action | active membership and tenant context | Implemented foundation |
| Configure academic structure | Tree + inspector | hierarchy actions/queries | allowed type edges and closure cycle safety | Schema/models only |
| Prepare faculty/rooms | Tables, detail/availability editor | resource actions/queries | paired scheduling-resource identity and availability precedence | Schema/models only |
| Prepare offerings | Readiness table/editor | catalog/offering actions | copied component requirements and eligible instructors | Schema/models only |
| View timetable | Grid or agenda with filters | authorized timetable query | canonical entries plus effective exceptions | Not implemented |
| Validate class | Entry inspector conflict list | JSON validation command | canonical constraint handlers | Basic endpoint; incomplete handlers |
| Save/move class | Grid/editor mutation | transactional schedule command | locks, hard validation, reservation exclusion, optimistic version | Create only; concurrency incomplete |
| Compare/rollback | Version list and paired diff | version queries/actions | immutable snapshots and logical entry lineage | Clone/publish partial |
| Approve timetable | Approval inbox/decision dialog | idempotent approval command | active step eligibility and append-only snapshot | Submission only |
| Publish timetable | Publication summary | locked publication action | full revalidation and one published version | Basic action only |
| Map/export Excel | Guided mapper and run status | queued file/template commands | safe immutable assets and versioned mapping | Schema only |
| Enforce plan | Navigation/action feedback | entitlement and usage guards | central capability/limit resolution | Service partial; tests failing |
| Review audit | Cursor-paginated log | authorized audit query | append-only redacted events | Schema/logger only |

## Shared state vocabulary

Backend enums, database constraints, frontend labels, filters, and status colors must use one canonical state vocabulary.

### Timetable versions

| State | Editable | Primary UI action | Allowed next states |
|---|---:|---|---|
| `draft` | yes | Submit for review | `in_review` |
| `in_review` | no | Review/decision | `changes_requested`, `approved` |
| `changes_requested` | yes | Resolve and resubmit | `in_review` |
| `approved` | no | Publish | `published` |
| `published` | no | Clone new version | remains historical |
| `superseded` | no | View/clone rollback | remains historical |

The current `TimetableVersionStatus` enum and database defaults must be reconciled with `changes_requested` before the approval UI is built.

### Async runs

Use `pending`, `running`, `completed`, `failed`, and `cancelled` consistently for workbook inspection, export, and future generation. Progress may be unknown; the UI must support indeterminate work.

### Approval instances and steps

Define instance and step enums before implementing decision endpoints. Database strings, transition services, page props, labels, and audit actions must share those enums.

## Cross-layer invariants

1. The UI never sends an organization ID as authority; route context and authenticated membership determine organization.
2. Public IDs cross HTTP boundaries; internal IDs remain behind backend mapping.
3. Frontend permission/capability props control presentation only; policies and guards enforce every command.
4. Client validation improves feedback but never substitutes for canonical server validation.
5. A successful schedule mutation returns authoritative entry data and lock versions; the client does not invent persisted state.
6. A 409 stale write preserves the draft and provides enough current state to compare or retry.
7. Canonical schedule reads power every teacher, group, room, academic-unit, and organization view.
8. Historical pages use versioned/snapshotted names, requirements, mappings, and signatories rather than mutable live profiles.
9. Async jobs and downloads reauthorize against organization context; possession of an ID or URL is insufficient.
10. Every critical mutation and async transition carries a correlation ID into audit, logs, and user-visible support details.

## Conflict contract review

The existing scheduling response already has stable issue concepts (`code`, `severity`, `field`, `resource`, `conflicting_entry_id`, `rule_code`, `message`, `details`). Preserve these while migrating resource/entry identifiers to public IDs and adding:

- `acknowledgement_required` for configured soft warnings;
- time/date details suitable for localized rendering;
- a stable `configuration_id` or scope reference when an administrator can edit the causing rule;
- optional authorized navigation target to the conflict, produced through Wayfinder-compatible route data rather than a hard-coded URL.

Messages remain server-provided fallbacks. The frontend primarily groups by stable codes and structured details, so copy can improve without changing behavior.

## Read-model review

Inertia page props must be tailored query results, not serialized Eloquent graphs. The backend should provide:

- `AcademicStructurePageData`
- `ResourceDirectoryPageData`
- `OfferingReadinessPageData`
- `TimetableWorkspacePageData`
- `TimetableVersionComparisonData`
- `ApprovalInboxPageData`
- `ExcelTemplateMapperPageData`
- `SubscriptionUsagePageData`

These are read contracts, not persistent domain models. They can combine modules after authorization and should use cursor pagination or deferred props for large collections.

## Navigation and route review

- Keep `{current_organization}` as the product route prefix.
- Introduce domain routes only when their policy and page-query contract exist.
- Use shallow public-ID routes for aggregate roots and explicit nested routes where the parent enforces context.
- Generate Wayfinder types after route changes and import them from `@/actions` or `@/routes`.
- Remove starter-kit repository/documentation links when product navigation is introduced, not as an isolated cleanup task.

## Frontend/backend sequencing risks

| Risk | Required sequencing response |
|---|---|
| UI built against internal numeric IDs | Complete the public-ID contract before domain pages |
| Pages hide controls but endpoints permit commands | Implement policy/capability tests before UI completion |
| Grid behavior outruns update/delete APIs | Build read view first, then complete command set and locks, then drag/drop |
| Approval UI assumes undefined states | Finalize enums and transition service first |
| Template mapper assumes synchronous parsing | Build queued inspection/status contract before mapping UI |
| Counts used as subscription limits race | Implement usage reservations before member/timetable limit UI |
| SQLite masks production invariants | Add PostgreSQL CI before scheduling mutation breadth |

## Cross-layer completion rules

A feature checklist item is complete only when applicable layers are all present:

- schema constraints and migration/backfill safety;
- model/value-object casts and tenant-safe relationships;
- action/query contract with authorization and capabilities;
- stable success/error response;
- Inertia/Vue page and all loading/empty/error/forbidden/limit states;
- audit/observability hooks;
- unit/feature/PostgreSQL/browser coverage appropriate to risk;
- implementation-plan status updated after inspecting the repository.

## Review result

The layers are compatible, but implementation should pause at the foundation. The recommended next task is to restore the existing test/lint/format baseline, beginning with the malformed subscription test fixture, before making tenant or public-ID contract changes.
