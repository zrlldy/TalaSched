# Backend Design

This document expands the accepted [system architecture](../architecture/ARCHITECTURE.md) into backend contracts. It describes the target design and calls out current repository progress where relevant. It does not authorize production-code changes or new dependencies.

## Backend objectives

The backend must guarantee three properties before convenience or optimization:

1. no organization can read or mutate another organization's data;
2. no committed timetable version can contain an invalid or double-booked resource reservation;
3. published schedules and their approvals remain historically reproducible.

Laravel remains the application boundary, PostgreSQL 17 the authoritative store, Redis the cache/lock/queue support, and S3-compatible storage the private artifact store. PostgreSQL production role privileges, UTC session requirements, TLS expectations, and readiness verification are defined by [ADR 0004](../decisions/0004-postgresql-production-baseline.md).

## Module ownership and dependencies

| Module | Owns | May depend on |
|---|---|---|
| Identity | users, authentication credentials, passkeys, 2FA | framework authentication only |
| Organizations | organizations, memberships, invitations, tenant context | Identity, Subscriptions for limits |
| Authorization | roles, permissions, scoped assignments, permission resolution | Organizations, Academic hierarchy queries |
| Academic | years, periods, unit types, units, closure, calendars, groups | Organizations |
| Resources | schedulable resource identity, faculty, rooms, features, availability | Organizations, Academic |
| Catalog | subjects, components, offerings, eligible instructors | Organizations, Academic, Resources |
| Scheduling | timetables, versions, entries, reservations, constraints, exceptions, views | Academic, Resources, Catalog, Authorization, Subscriptions, Audit |
| Approvals | workflow definitions/versions, instances, actions, signatory snapshots | Scheduling, Authorization, Audit, Files |
| Files/Templates | file assets, workbook templates/versions, exports | Scheduling, Approvals, Authorization, Subscriptions, Audit |
| Subscriptions | plans, capabilities, subscriptions, overrides, usage | Organizations |
| Audit | append-only audit records and correlation context | Identity and Organizations identifiers only |

Cross-module writes occur through actions or contracts owned by the target module. Models must not acquire convenience methods that silently mutate another module's aggregate.

## Application layers

```text
HTTP / Inertia / Queue / CLI adapters
              ↓
Form Requests + Policies + capability guards
              ↓
Command Actions / Query Objects
              ↓
Domain services, value objects, constraint handlers
              ↓
Eloquent models + PostgreSQL constraints
              ↓
Audit/outbox records and after-commit jobs
```

- **Form Requests** validate transport shape and tenant-safe identifier existence.
- **Policies** answer whether the actor may perform the operation in the current organization and optional academic scope.
- **Capability guards** answer whether the organization subscription enables the operation or has remaining quota.
- **Actions** own transaction boundaries, locks, state changes, and audit records.
- **Queries** shape authorized read data for Inertia or JSON without mutating state.
- **Handlers** implement typed constraint behavior. Database JSON contains configuration, not executable logic.
- **Jobs** carry organization identity, establish tenant context, and are idempotent.

Do not introduce generic repositories over Eloquent. Use ports only at replaceable external boundaries.

## Tenant contract

### Request resolution

1. Resolve `{current_organization}` by slug before tenant-owned nested bindings.
2. Authenticate the user and verify active organization membership.
3. Establish `TenantContext` and the PostgreSQL `app.current_organization_id` setting.
4. Resolve nested resources using organization-qualified queries or scoped binding.
5. Authorize the action through a policy and academic-unit permission scope.
6. Clear tenant context in a `finally` block after the response.

An absent tenant context must deny access to domain tenant tables under RLS. The database application role must not own RLS-protected tables and must not have `BYPASSRLS`. `php artisan database:verify-production` must pass against PostgreSQL before the database is considered production-ready.

`organization_members` and `organization_invitations` are identity control-plane tables under [ADR 0003](../decisions/0003-identity-control-plane-rls-boundary.md). They remain organization-owned and require tenant-safe keys, but are excluded from organization-only RLS because membership discovery, organization switching, and invitation acceptance occur before one organization context exists. Queries against them must always be qualified by the authenticated actor, normalized recipient identity, or an already-authorized organization.

### Jobs and commands

Every tenant job payload includes an immutable organization public ID and the subject public ID, not a serialized authenticated user session. Job middleware resolves the organization outside RLS, sets tenant context, performs the work, and clears context. CLI commands that touch tenant data require an explicit organization argument or iterate organizations by establishing and clearing context for each one.

### Tenant-safe references

Use composite foreign keys where practical so a child `(organization_id, foreign_id)` can only reference a parent with the same organization. Where Laravel migration or nullable-key limitations make this awkward, enforce the same invariant in the action and a database trigger/constraint, then cover it with PostgreSQL tests.

## Identifier strategy

- Internal bigint IDs remain database implementation details and relationship keys.
- Aggregate roots exposed across HTTP use `public_id` UUIDs or established organization slugs.
- New frontend contracts must not expose internal numeric IDs for organizations, academic records, resources, offerings, timetables, versions, entries, approvals, templates, exports, or file assets.
- The current scheduling endpoints accept numeric IDs; migrate them through an explicit compatibility step rather than silently changing existing tests and clients.
- Human codes such as subject, room, and group codes are labels, not stable route identifiers.

## HTTP contract conventions

### Web and JSON boundaries

- Use Inertia GET endpoints for complete product pages and authorized page props.
- Use standard Inertia form submissions for ordinary CRUD and state transitions where redirects and flash messages are appropriate.
- Use JSON command endpoints for high-frequency scheduling validation/mutation, async run status, and interactions requiring structured conflicts.
- Use Wayfinder-generated route functions in the Vue client.
- Keep all routes organization-prefixed except invitation acceptance and identity settings that must exist before organization context.

### Mutation requirements

- Authenticate, establish tenant context, authorize, then validate.
- Require `Idempotency-Key` for retryable commands that create approval actions, publication side effects, exports, subscription events, or future generation runs.
- Require `lock_version` for updates to mutable timetable versions and schedule entries.
- Return the new `lock_version` after successful mutations.
- Emit audit/outbox records in the same transaction; dispatch external work after commit.

### Success shapes

Ordinary Inertia mutations redirect with a stable flash message. JSON commands use a consistent resource envelope:

```json
{
  "data": {
    "id": "01J...",
    "type": "schedule_entry",
    "attributes": {}
  },
  "meta": {
    "correlation_id": "01J..."
  }
}
```

Collections use cursor pagination once a dataset can grow beyond a few hundred records and declare stable ordering in `meta`.

### Error shape

```json
{
  "error": {
    "code": "schedule_conflict",
    "message": "This class conflicts with existing schedule rules.",
    "issues": [
      {
        "code": "resource_overlap",
        "severity": "hard",
        "field": "resources",
        "rule_code": "resource_overlap",
        "resource": { "id": "01J...", "name": "Room 301" },
        "conflicting_entry_id": "01J...",
        "message": "Room 301 is already assigned from 08:00 to 09:30.",
        "details": {}
      }
    ]
  },
  "meta": { "correlation_id": "01J..." }
}
```

Stable top-level codes include `validation_failed`, `forbidden`, `not_found`, `capability_required`, `limit_reached`, `stale_write`, `schedule_conflict`, `invalid_state_transition`, `idempotency_conflict`, `rate_limited`, and `service_unavailable`.

| Condition | Status |
|---|---:|
| Transport validation failure | 422 |
| Scheduling hard conflict | 422 |
| Missing capability or permission | 403 |
| Tenant-scoped resource not found | 404 |
| Optimistic lock mismatch | 409 |
| Idempotency key reused with different input | 409 |
| Database serialization/deadlock exhausted | 409 or 503 with retry guidance |
| Rate limit | 429 |

Authorization failures must not reveal whether a resource exists in another organization.

## Principal use-case contracts

### Organization and membership

- `CreateOrganization`: creates the organization, owner membership, built-in role assignments, initial entitlement/subscription state, and default configuration transactionally.
- `InviteOrganizationMember`: normalizes email, enforces member limits, stores a hashed token, records requested roles, and sends notification after commit.
- `AcceptOrganizationInvitation`: locks the invitation, checks expiry/email/state, creates or updates membership and assignments, marks accepted, increments usage, and is replay-safe.
- `TransferOrganizationOwnership`: locks organization and both memberships, prevents last-owner loss, updates owner and roles, and audits before/after state.
- `RemoveOrganizationMember`: protects the owner, decrements reserved member usage, revokes scoped assignments, and clears an affected user's current organization.

### Academic hierarchy

- `CreateAcademicUnit`: validates type edge and active dates, inserts adjacency and closure rows in one transaction.
- `MoveAcademicUnit`: locks the moving subtree and destination lineage, rejects cycles/type mismatch, rewrites closure rows, and increments a hierarchy revision.
- `ArchiveAcademicUnit`: prevents orphaning active dependent records or requires an explicit reassignment plan.
- Hierarchy queries accept a root public ID and return ancestors/descendants with depth; they do not reconstruct paths recursively in PHP.

### Resource and catalog setup

- Creating faculty, rooms, and student groups creates the paired `scheduling_resources` row in the same transaction.
- Resource type is immutable once specialized data or reservations exist.
- Subject edits affect catalog defaults only. Offering components copy schedule-relevant values and preserve period history.
- Eligible instructors belong to an offering component; selecting an instructor during scheduling must validate eligibility unless an authorized override rule exists.
- Availability resolution uses organization operating hours, optional hard available windows, unavailable blocks, then preferred/avoid soft windows. Effective dates and academic period scope participate in matching.

### Schedule validation

`ValidateScheduleEntry` accepts a complete proposed assignment and optional existing entry ID for updates. It returns hard issues and soft warnings without persistence.

Handler interface:

```text
ConstraintHandler
  code(): ConstraintCode
  supports(context, configuration): bool
  evaluate(SchedulingContext, ConstraintConfiguration): ConstraintResult
```

`SchedulingContext` is immutable and includes organization, period/calendar, version, offering component, proposed time, assigned resources, effective dates, existing entry for updates, and prefetched relevant reservations/availability. Handlers must not issue uncontrolled per-resource queries.

Mandatory hard handlers:

- editable version and valid state;
- time range, granularity, duration, and operating calendar;
- resource role/type and active state;
- offering/group integrity and instructor eligibility;
- resource overlap;
- resource availability/effective dates;
- room delivery mode, capacity, type, and features;
- faculty daily/weekly load;
- offering session count and weekly-minute integrity;
- dated exception consistency.

Soft handlers include preferred/avoid windows, consecutive-load limits, gap minimization, room preference/change minimization, day preference, late-session avoidance, and weekly distribution.

### Create or update schedule entry

1. Resolve public identifiers under tenant context.
2. Authorize and check capability.
3. Begin a PostgreSQL transaction.
4. Lock the timetable version and acquire sorted advisory locks derived from organization/version/resource IDs.
5. Evaluate the canonical validator using transaction-consistent data.
6. Reject hard issues; require explicit warning acknowledgement only for configured acknowledgement-grade soft issues.
7. Write entry and assignments; rebuild reservation projections.
8. Let the exclusion constraint provide the final overlap guard.
9. Increment `lock_version`, append audit/outbox records, and commit.
10. Translate known PostgreSQL violations into stable structured issues.

Validation is advisory; creation/update is authoritative and always revalidates under locks.

### Effective schedule and exceptions

Read queries combine weekly entries with period dates, calendar exceptions, and entry exceptions. A dated exception may cancel, reschedule, or replace resources for one occurrence. It must reserve effective resources for the affected date under date/resource advisory locks. Do not rewrite the recurring source entry to represent one-date changes.

### Version lifecycle

Allowed transitions:

```text
draft ──submit──> in_review ──approve──> approved ──publish──> published
  ▲                  │
  └──request changes─┘
published ──new version/rollback clone──> new draft
published ──superseded by publish──> superseded
```

- Only draft and changes-requested versions are editable.
- Clone preserves logical entry IDs but assigns new public row IDs and copies exceptions and all version-owned data.
- Publication locks the timetable, reruns every mandatory hard constraint, supersedes the prior published version, publishes the approved version, and writes audit/outbox records in one transaction.
- Compare versions by logical entry ID, then classify addition, removal, time move, resource reassignment, and content change.

### Approval lifecycle

- Workflow edits create a new definition version; activated versions are immutable.
- Submission snapshots steps and eligibility selectors into the approval instance.
- Decision actions lock the active step, verify eligibility and self-approval policy, enforce an idempotency key, append the decision/signatory snapshot, and advance or terminate the instance.
- Rejection ends the instance. Request changes returns the timetable version to an editable state while retaining the historical instance.
- The final required approval moves the timetable version to `approved`; publication remains a distinct authorized command.

### Entitlements and usage

- `EntitlementService` resolves override, active subscription plan value, then a deny/no-limit result according to capability type.
- `UsageService` owns quota reads and transaction-safe reservations. It must not derive concurrency-sensitive limits by counting rows without locks.
- Domain actions call a capability/usage guard; controllers and UI visibility are not enforcement boundaries.
- Downgrades do not delete data. They block new over-limit actions while preserving authorized reads and explicit remediation paths.

### Files, templates, and exports

- Upload writes a quarantined `file_asset`; parsing is forbidden until validation/scanning succeeds.
- Workbook inspection and export are idempotent queued jobs with bounded CPU, memory, file size, ZIP expansion, and execution time.
- A template version references an immutable source asset and typed, versioned mapping.
- An export run snapshots source timetable-version ID, approval/signatory context, template-version ID, locale/timezone, and an input hash.
- Equivalent successful input hashes may reuse artifacts if authorization and retention permit.
- Generated artifacts are private and downloaded through short-lived, policy-checked URLs.

## Persistence rules

### Database constraints

- Add check constraints for positive date/time ranges, minute bounds, weekday bounds, valid granularity, nonnegative capacities/loads, and typed capability values.
- Add composite tenant-safe foreign keys for tenant-owned references.
- Use partial unique indexes for one published version, one current subscription according to finalized status semantics, and one active approval instance where resubmission requires history.
- Use GiST exclusion for recurring resource overlap and a safe dated-reservation mechanism for exceptions.
- Keep audit and approval actions append-only through application policy and database permissions/triggers where justified.

### Migration strategy

Use expand/backfill/verify/contract migrations for existing tables:

1. add nullable columns/indexes without changing reads;
2. deploy dual-write or backfill in bounded batches;
3. verify tenant invariants and counts;
4. switch reads;
5. make columns non-null or remove legacy fields in a later deployment.

PostgreSQL-specific constraints must have integration tests. SQLite migration success is not release evidence for RLS, GiST, partial indexes, or advisory locking.

## Events and asynchronous work

Use local domain events for in-process reactions and a transactional outbox when a committed action must reliably trigger queued or external work. Initial event names include:

- `OrganizationCreated`
- `OrganizationMemberInvited`
- `TimetableVersionSubmitted`
- `ApprovalStepCompleted`
- `TimetableVersionApproved`
- `TimetableVersionPublished`
- `ExcelTemplateInspectionRequested`
- `TimetableExportRequested`
- `SubscriptionChanged`

Consumers are idempotent and record processed event/message IDs. Queue names should separate interactive notifications, workbook processing, exports, and future optimization so one workload cannot starve another.

## Caching and read performance

- Cache permission and entitlement resolution per organization/membership with explicit revision keys.
- Cache stable academic hierarchy reads by organization hierarchy revision.
- Do not cache mutation validation results as authoritative.
- Build query objects for timetable views with explicit eager loading and bounded date/resource filters.
- Cursor-paginate audit events, faculty, subjects, offerings, and large resource lists.
- Invalidate caches after commit; cache failure must degrade to database reads, not incorrect authorization.

## Observability

Every request/job receives a correlation ID. Critical structured logs include organization public ID, actor public-safe identifier, action, subject public ID, result code, duration, retry count, and lock wait; they exclude tokens, signatures, raw workbook content, and secret configuration.

Minimum metrics:

- HTTP and command latency/error rate by route/action;
- schedule validation duration and issue codes;
- exclusion violations, stale writes, deadlocks, and advisory-lock waits;
- RLS denials and missing tenant context;
- approval decision outcomes and time in step;
- entitlement denials and usage saturation;
- queue depth/oldest age, job duration/retries/failures;
- upload scan/parse failures and export completion time.

## Test architecture

- **Unit:** value objects, state transitions, configuration schema validation, individual constraint handlers, mapping DTOs.
- **Feature:** actions through HTTP, policies, capabilities, structured errors, audit records, idempotency.
- **PostgreSQL integration:** RLS, composite references, exclusion constraints, advisory locks, hierarchy moves, publication and approval races.
- **Contract:** JSON error/resource shapes, Wayfinder routes, solver and billing ports.
- **Golden artifact:** representative `.xlsx` workbooks and historical signatory output.
- **Architecture:** forbidden module dependencies, no plan-name branching, no tenant models queried outside tenant context in owned paths.

Factories must create complete valid aggregates and provide explicit states such as `published`, `withRoomRequirement`, `unavailableOnMonday`, and `atMemberLimit` rather than forcing tests to hand-assemble inconsistent rows.

## Current implementation gaps

The immediate backend work remains Phase 0 and Phase 1 of the [implementation plan](../plans/implementation-plan.md): repair the failing entitlement fixture, restore quality checks, complete factories, establish production PostgreSQL behavior, and harden tenant isolation. New scheduling breadth should wait until these foundations are green.
