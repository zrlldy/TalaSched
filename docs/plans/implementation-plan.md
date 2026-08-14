# TalaSched Implementation Plan

This document is the source of truth for implementation progress. Read it before starting work, verify each task against the current repository, and continue from the next dependency-safe incomplete task.

The target design and its trade-offs are documented in the [system architecture](../architecture/ARCHITECTURE.md), [architecture review](../architecture/REVIEW.md), [backend design](../backend/BACKEND_DESIGN.md), [frontend architecture](../frontend/FRONTEND_ARCHITECTURE.md), [cross-layer review](../architecture/CROSS_LAYER_REVIEW.md), and [ADRs](../decisions/). This checklist records the implementation state observed on 2026-08-14; it does not treat the presence of a migration or class as proof that a feature is complete.

## Backend Development

Use the `senior-backend` skill for backend/domain/database/API work.

Before backend work:

1. Read relevant documents under `docs/architecture/`.
2. Read relevant documents under `docs/backend/`.
3. Read applicable ADRs under `docs/decisions/`.
4. Read `docs/plans/implementation-plan.md`.
5. Inspect the current backend implementation.

The backend must follow the documented architecture.

Do not change major architectural decisions without proposing the change first.

Backend responsibilities include:

- database schema and migrations
- domain models and business rules
- services/application logic
- APIs/controllers
- validation
- authorization
- tenant isolation
- subscription enforcement
- events/jobs/queues
- integrations
- backend tests

Keep business logic out of controllers where practical.

Authorization, tenant isolation, and subscription limits must be enforced
server-side.

For each task:

1. Implement the smallest dependency-safe change.
2. Add/update relevant tests.
3. Run relevant checks.
4. Update backend documentation when necessary.
5. Update `docs/plans/implementation-plan.md`.
6. Mark a task [x] only when fully implemented and verified.

Do not rely on previous Codex conversations to determine project state.

## Frontend Development

Use the `frontend-design` skill for frontend architecture, UI/UX,
component design, and frontend implementation.

Before frontend work:

1. Read relevant documents under `docs/frontend/`.
2. Read relevant backend contracts under `docs/backend/`.
3. Read applicable ADRs.
4. Read `docs/plans/implementation-plan.md`.
5. Inspect the existing frontend implementation.

The frontend must follow the documented backend contracts and system
architecture.

Do not change backend APIs, database design, or system architecture
without proposing the change first.

Prefer reusable components and consistent design patterns.

Every user-facing feature should consider:

- loading state
- empty state
- validation state
- error state
- permissions
- subscription restrictions
- responsiveness
- accessibility

Frontend restrictions must not be treated as security controls.
Authorization and subscription enforcement must also exist server-side.

## Status conventions

- `[x]` means the narrowly stated task is implemented and was found in the repository.
- `[ ]` means work remains.
- A task with a **Progress:** note is partially implemented and must remain `[ ]` until its acceptance criteria are satisfied.
- Add newly discovered work under the earliest dependency-safe phase that owns it.

## Current baseline

- Laravel 13 / PHP 8.4 with Inertia 3, Vue 3, Fortify, Wayfinder, Pest 5, and Tailwind CSS.
- Development currently uses SQLite. PostgreSQL, Redis, queue workers, and S3-compatible storage remain production targets rather than verified runtime infrastructure.
- FilamentPHP, Laravel Excel, and PhpSpreadsheet are not installed. The current administration experience is Inertia/Vue.
- The repository contains the organization/authentication foundation, broad domain schema, initial manual scheduling APIs, and initial timetable version actions.
- No automatic scheduling engine, Excel processing pipeline, or complete approval workflow is implemented.

### Planning and design deliverables

- [x] Persist the overall system architecture and target domain model.
- [x] Review the architecture against the current repository for completeness and consistency.
- [x] Create detailed backend contracts covering tenancy, APIs, transactions, concurrency, state transitions, errors, async work, observability, and testing.
- [x] Create the frontend information, interaction, visual, accessibility, and responsive architecture.
- [x] Complete a cross-layer review mapping user journeys to backend boundaries and invariants.
- [x] Formalize the existing modular-monolith decision in ADR 0001.
- [x] Resolve the public-registration policy in ADR 0002.
    - **Decision:** public registration remains enabled, registration creates no organization automatically, and unaffiliated users may explicitly create an organization or accept an invitation.
- [ ] Resolve the open decisions listed in `docs/architecture/REVIEW.md` when each becomes necessary; do not infer answers during implementation.

### Verification status at audit

- [x] TypeScript type checking passes with `npm run types:check`.
- [x] The application migrations complete on SQLite.
- [x] Basic timetable clone and publish tests pass.
- [x] Restore the complete Pest suite to green.
    - **Verified:** 105 tests and 393 assertions pass with `php artisan test --compact` on 2026-08-14.
- [x] Restore frontend linting to green.
- [x] Restore frontend formatting checks to green.
- [ ] Run and record PHP formatting and static-analysis checks after the current domain work is stabilized.
    - **Progress:** non-parallel Pint formatting passes. The parallel Pint check cannot open its local TCP coordination socket in this environment (`EPERM`), and `vendor/bin/phpstan analyse --debug --no-progress` still exits with code 255 without diagnostics. Re-run both in a suitable environment before changing this item.

## Phase 0 — Stabilize the repository baseline

- [x] Persist the system architecture in `docs/architecture/ARCHITECTURE.md`.
- [x] Rename the existing Team-facing application surface to Organization across routes, models, pages, and tests.
- [x] Retain working Fortify authentication flows for registration, login, password reset, email verification, two-factor authentication, passkeys, and profile settings.
- [x] Fix the failing subscription entitlement tests without weakening foreign-key enforcement.
- [x] Apply the existing frontend lint and formatting rules to the files identified above.
- [x] Create the required frontend architecture baseline under `docs/frontend/` from the audited Inertia/Vue implementation before feature UI work begins.
- [x] Introduce semantic scheduling color and typography tokens without changing authentication or organization behavior.
    - **Verified:** light and dark token contracts cover schedule selection, warnings, conflicts, availability, ledger surfaces, and tabular schedule data; the focused token test and frontend production checks pass.
- [x] Restore Laravel 13 request compatibility so Artisan, Wayfinder generation, and the frontend production build can boot.
    - **Verified:** renamed the DTO conversion method to `scheduleEntryData()`, updated both scheduling controller callers, regenerated Wayfinder definitions, and passed scheduling tests and the production build.
- [x] Fill the empty factories for academic years, academic periods, academic units, faculty, rooms, subjects, offerings, student groups, timetables, versions, and schedule entries.
    - **Verified:** default factories derive tenant ownership from their parent aggregates; scheduling resources have typed states, and schedule entries create instructor/group/room assignments with matching reservations.
- [x] Add focused factory smoke tests so future domain tests can build valid tenant-owned aggregates reliably.
    - **Verified:** `DomainFactorySmokeTest` passes 4 tests and 32 assertions covering academic, resource, catalog, and scheduling aggregates, including the underlying resources assigned to a schedule entry.
- [x] Run the minimum affected tests after each correction, then run the full compact suite once the baseline is green.
    - **Verified:** factory smoke coverage passes 4 tests and 32 assertions; the latest full suite passes 121 tests and 532 assertions. Production build, TypeScript, ESLint, and Prettier checks also pass.
- [x] Introduce public-ID HTTP contracts for the existing scheduling endpoints before building new domain administration pages.
    - **Verified:** timetable versions, offering components, and scheduling resources are accepted as tenant-scoped UUIDs and resolved to internal keys only after validation. Schedule-entry responses and conflict details expose public UUIDs through an explicit API resource. Numeric-key and cross-tenant contract tests pass.
- [x] Standardize JSON resource/error envelopes, correlation IDs, idempotency keys, and optimistic-lock responses according to the backend design before expanding command endpoints.
    - **Verified:** scheduling success and error responses use stable `data`/`error` plus `meta.correlation_id` envelopes; correlation IDs propagate through response headers, logging context, and audit records. Schedule creation requires a tenant/user/route-scoped idempotency key, replays completed responses without duplicate writes, rejects changed input with `409 idempotency_conflict`, and prunes expired records daily through explicit per-organization tenant context. A reusable `409 stale_write` response includes authoritative current state for future update endpoints. Contract, tenant-isolation, audit-correlation, replay, pruning, and full-suite tests pass.

**Exit criteria:** migrations and all existing tests, type checks, lint checks, and formatting checks pass without changing product behavior.

## Phase 1 — Runtime infrastructure and tenant foundation

- [x] Add organization identity and configuration columns: public ID, owner, timezone, locale, scheduling granularity, and status.
- [x] Add request middleware and a tenant context that set and clear the PostgreSQL organization session setting.
- [x] Add a conditional PostgreSQL row-level security migration for tenant-owned tables.
- [x] Complete organization lifecycle behavior.
    - **Verified:** registration and default user factories no longer create personal organizations; explicit organization creation assigns and selects the owner tenant transactionally. Unaffiliated users are redirected to organization setup and can review invitations there. Leaving, member removal, and organization deletion deterministically select another membership or clear the current organization. The legacy personal marker is removed without deleting existing organizations, and the full Pest suite passes 128 tests and 604 assertions.
- [x] Migrate legacy organization and membership Inertia props and mutation inputs away from internal numeric IDs while completing the organization lifecycle.
    - **Verified:** organization list, shared-current-organization, and detail props expose organization public UUIDs while documented navigation continues to use slugs. Memberships now receive backfilled, unique, non-null public UUIDs; member update/removal routes bind those UUIDs, enforce organization ownership, and reject numeric or cross-organization keys. The user's internal current-organization foreign key is hidden from Inertia serialization, Wayfinder definitions were regenerated, and the full Pest suite passes 132 tests and 624 assertions.
- [x] Backfill and enforce final nullability/uniqueness rules for organization public IDs and owners using a safe expand/backfill/contract migration sequence.
    - **Verified:** bounded backfill and verification run before the contract migration; organization public IDs and explicit owners are non-null, public IDs remain unique, and owner-user deletion is restricted. Organization creation and factories atomically establish the matching owner membership, soft deletion preserves that ownership record, and profile deletion returns a validation error while active or historical ownership remains. The migrated SQLite database has no missing or mismatched identities, and the full Pest suite passes 136 tests and 640 assertions.
- [x] Define a single authoritative tenant-query strategy for HTTP, queued jobs, CLI commands, and scheduled tasks.
    - **Verified:** `TenantContext` is the single execution boundary, with exception-safe nested `run`, public-ID `runByPublicId`, and active-organization iteration APIs that set and clear PostgreSQL session state and public logging context. HTTP membership middleware delegates to it; reusable queue middleware resolves immutable organization UUIDs; queued invitation notifications declare that middleware; and both scheduled maintenance commands iterate tenants through the same boundary. Focused execution, queue, command, scheduler-registration, and notification tests pass, and the full Pest suite passes 141 tests and 665 assertions.
- [ ] Audit every tenant table for composite tenant-safe foreign keys or equivalent application invariants.
    - **Progress:** most tables carry `organization_id`, but cross-table references do not consistently prove both rows belong to the same organization.
- [ ] Verify RLS policies, forced RLS behavior, connection-pool cleanup, and bypass roles against PostgreSQL in automated integration tests.
- [ ] Configure and verify PostgreSQL as the supported production database.
- [ ] Configure Redis for cache, sessions where selected, queues, throttling, and distributed locks.
- [ ] Configure private S3-compatible object storage and signed-download authorization.
- [ ] Hash organization invitation tokens at rest, add expiry/acceptance invariants, and avoid retaining reusable plaintext secrets.
- [ ] Add cross-tenant request, route-model-binding, nested-ID, direct-query, queued-job, and file-download isolation tests.

**Exit criteria:** tenant context is explicit in every execution mode, PostgreSQL isolation is tested, and no user-controlled identifier can cross organization boundaries.

## Phase 2 — Roles, permissions, and policy enforcement

- [x] Create normalized role, permission, role-permission, and membership-role-assignment tables.
- [ ] Define and seed the canonical permission catalog and immutable built-in organization roles.
- [ ] Replace runtime checks against the legacy `organization_members.role` value with normalized role assignments.
    - **Progress:** normalized tables exist, but current organization membership and invitation flows still use the fixed role enum/column.
- [ ] Support optional academic-unit scope on role assignments using the academic hierarchy closure table.
- [ ] Implement a permission resolver with request-local caching and explicit cache invalidation.
- [ ] Create policies for organizations, memberships, invitations, academic records, resources, schedules, versions, approvals, templates, subscriptions, and audit records.
- [ ] Prevent privilege escalation, owner removal, last-owner loss, and unauthorized role delegation.
- [ ] Gate custom-role administration behind the subscription capability system.
- [ ] Build organization role and member administration pages with clear scope and capability feedback.
- [ ] Add authorization matrix tests for built-in roles, custom roles, academic-unit scope, and multiple-organization users.

**Exit criteria:** all protected operations use policies/permissions rather than fixed role comparisons or controller-only checks.

## Phase 3 — Academic structure and calendars

- [x] Create schema for academic years, periods, unit types, allowed type edges, units, hierarchy closure, calendars, calendar exceptions, and student groups.
- [x] Add initial Eloquent models and enums for the principal academic entities.
- [ ] Complete model relationships, casts, tenant invariants, factories, and deletion rules.
- [ ] Implement the academic hierarchy service to create, move, archive, and query units transactionally.
- [ ] Maintain closure-table rows and reject cycles under concurrency.
- [ ] Validate allowed parent/child unit-type edges while keeping unit types configurable per organization.
- [ ] Add optional preschool, K–12, senior-high, university, and training-center presets that create ordinary configurable units rather than special-case schema.
- [ ] Implement academic year and configurable period lifecycle rules without assuming semesters.
- [ ] Implement calendars, operating hours, holidays, blocked dates, and exceptional teaching dates.
- [ ] Implement student-group membership in the hierarchy and active-date handling.
- [ ] Add actions, requests, policies, routes, and Inertia/Vue administration pages for academic setup.
- [ ] Add tests for arbitrary valid hierarchies, invalid edges, cycle prevention, period boundaries, and tenant isolation.

**Exit criteria:** an organization can configure each target institution structure and calendar without code or schema changes.

## Phase 4 — Faculty, rooms, resources, subjects, and offerings

- [x] Create the scheduling-resource backbone and schemas for faculty, rooms, features, availability, subjects, subject components, offerings, and student groups.
- [x] Model teachers, rooms, and groups as schedulable resources suitable for one canonical conflict mechanism.
- [ ] Complete resource model relationships, value objects, factories, archival behavior, and tenant-safe constraints.
- [ ] Implement faculty profiles, employment metadata, department links, daily/weekly load limits, availability, and preferences.
- [ ] Implement campus/building/room management, configurable room types, capacities, features, and availability.
- [ ] Implement subject/course catalogs with lecture/laboratory components, units, required hours, default duration, and room requirements.
- [ ] Implement period-specific offerings that associate subjects, groups, eligible instructors, required sessions, and delivery requirements without permanently assigning teachers to subjects.
- [ ] Define precedence and overlap validation for resource availability rules.
- [ ] Build actions, policies, routes, and Inertia/Vue administration pages for faculty, rooms, features, subjects, offerings, and availability.
- [ ] Add import preparation points for bulk faculty, room, subject, and offering data without implementing a generic importer prematurely.
- [ ] Add CRUD, validation, authorization, availability, and cross-tenant tests.

**Exit criteria:** schedulers can prepare all inputs required for manual scheduling through authorized administration workflows.

## Phase 5 — Manual scheduling and conflict prevention

- [x] Create canonical timetable, version, schedule-entry, entry-resource, reservation, and dated-exception schema.
- [x] Add a PostgreSQL exclusion constraint for overlapping reservations and a partial uniqueness rule for a published version when PostgreSQL is used.
- [x] Implement JSON endpoints to validate and create recurring schedule entries.
- [x] Return structured conflict issues for the current validator checks.
- [x] Add initial tests for valid creation, resource overlap, availability, room requirements, and faculty load.
- [ ] Refactor the monolithic validator into a registry of typed hard- and soft-constraint handlers backed by database configuration.
    - **Progress:** current checks live in `ValidateScheduleEntry`; rule behavior is not yet extensible through dedicated handlers.
- [ ] Add missing hard checks: calendar operating hours, blocked dates, positive available-window semantics, room features, instructor eligibility, offering hour/session fulfillment, exception dates, effective date ranges, and organization-wide blocked times.
- [ ] Add configurable soft-constraint evaluation and return warnings/scores separately from hard failures.
- [ ] Acquire deterministic PostgreSQL advisory locks for all affected resources before conflict validation and insertion.
- [ ] Translate database exclusion/uniqueness violations into the same structured conflict response used by preflight validation.
- [ ] Add update, move, resize, and delete actions with optimistic locking and full revalidation.
- [ ] Implement per-date cancellation, replacement, and room/teacher substitution through schedule exceptions.
- [ ] Build canonical read models/queries for teacher, student-group, room, unit, and organization timetable views without duplicating schedule records.
- [ ] Build the Inertia/Vue timetable grid, filters, entry editor, conflict panel, and accessible non-grid view.
- [ ] Add PostgreSQL concurrency tests proving simultaneous requests cannot double-book teachers, rooms, or groups.
- [ ] Add policy, entitlement, audit, and tenant-isolation coverage to every scheduling mutation.

**Exit criteria:** authorized schedulers can safely create and maintain a timetable manually, and the database remains the final guard against double booking.

## Phase 6 — Timetable versioning and publication

- [x] Model immutable timetable versions with draft, review, approved, published, and superseded states.
- [x] Implement initial clone and publish actions.
- [x] Add passing basic clone and publish tests.
- [ ] Clone schedule exceptions and all version-owned metadata, not only entries/resources/reservations.
- [ ] Add version comparison by stable entry lineage and present added, removed, moved, reassigned, and changed entries.
- [ ] Implement rollback as cloning an older version into a new draft; never mutate published history.
- [ ] Run complete hard-constraint validation immediately before approval completion and publication.
- [ ] Add state-machine transition guards, authorization, capabilities, audit events, domain events, and transactional outbox handling where external work follows publication.
- [ ] Add version list, clone, compare, submit, publish, supersede, and rollback UI.
- [ ] Add race-condition tests for concurrent publication and immutable-history tests.

**Exit criteria:** published versions cannot be edited, historical versions remain reproducible, and every transition is authorized and audited.

## Phase 7 — Approval workflows and signatories

- [x] Create workflow, workflow-version, step, approval-instance, approval-action, and signatory-profile schema.
- [x] Implement an initial action that snapshots workflow steps when a timetable version is submitted.
- [ ] Decide and document whether step eligibility needs a dedicated `approval_step_members` relation in addition to role/permission selectors.
- [ ] Implement versioned workflow authoring, validation, activation, and retirement.
- [ ] Implement approve, reject, request-changes, cancel, and resubmit actions with sequential step advancement.
- [ ] Resolve eligible approvers through organization roles, academic-unit scope, named memberships, or permissions.
- [ ] Configure separation-of-duties and self-approval rules per workflow.
- [ ] Complete approval by transitioning the timetable version to approved only after all required steps succeed.
- [ ] Implement signatory profile validity periods and private signature-image assets.
- [ ] Snapshot signatory name, position, organization unit, label, decision, and signature asset at approval time so history remains stable.
    - **Progress:** snapshot columns exist on approval actions; no complete approval action currently populates or protects them.
- [ ] Build workflow designer, approval inbox, decision forms, status timeline, and signatory administration UI.
- [ ] Add authorization, replay/idempotency, concurrent-action, historical-snapshot, and workflow-version tests.

**Exit criteria:** organizations can configure different approval chains and published outputs preserve the exact historical signatories.

## Phase 8 — Subscriptions, capabilities, and limits

- [x] Create plan, capability, plan-capability, organization-subscription, override, and usage schema.
- [ ] Complete and verify the entitlement resolver.
    - **Progress:** `EntitlementService` resolves boolean/integer plan values and organization overrides, but its current feature tests error because of invalid fixture insertion.
- [ ] Define capability metadata, value types, defaults, and seed Starter, Professional, and Enterprise plan fixtures.
- [ ] Define subscription lifecycle semantics for trialing, active, grace period, past due, canceled, and expired states.
- [ ] Implement centralized capability and numeric-limit checks through policies, middleware, domain guards, and reusable UI props.
- [ ] Implement transaction-safe usage reservations for limits such as members and active timetables.
- [ ] Add a billing-provider port and webhook idempotency ledger while keeping the initial provider optional.
- [ ] Integrate capability checks for automatic scheduling, Excel templates, approval workflows, custom roles, multi-campus, API access, and audit features.
- [ ] Build plan/usage/subscription administration pages and disabled-feature upgrade messaging.
- [ ] Add tests for overrides, downgrades below current usage, concurrent limit consumption, grace periods, and webhook replay.

**Exit criteria:** no product code compares plan names directly, and all features and limits are enforced centrally on the server.

## Phase 9 — Audit, security, and data governance

- [x] Create an append-only audit-event schema and an initial `AuditLogger` abstraction.
- [ ] Integrate audit logging into organization, membership, academic, resource, scheduling, version, approval, subscription, template, export, and security-sensitive actions.
- [ ] Capture actor, organization, target, request metadata, before/after summaries, correlation ID, and impersonation context without storing secrets.
    - **Progress:** `AuditLogger` now reuses the active request correlation ID and prefers public subject identifiers; impersonation context and systematic action integration remain incomplete.
- [ ] Define sensitive-field redaction, retention, export, and deletion policies.
- [ ] Add immutable-audit behavior and tests for critical actions.
- [ ] Enforce MIME, extension, ZIP-entry, decompression-size, macro, and formula/external-link policies for uploaded workbooks and signature assets.
- [ ] Add malware-scanning integration points and quarantine states for uploaded assets.
- [ ] Encrypt or otherwise protect sensitive signature assets and issue short-lived authorized downloads.
- [ ] Add rate limits for invitations, schedule validation, exports, uploads, and future generation jobs.

**Exit criteria:** critical changes are attributable, sensitive artifacts are private, and untrusted uploads cannot reach processing or download paths unchecked.

## Phase 10 — Excel template mapping and export

- [x] Create file-asset, Excel-template, template-version, and export-run schema.
- [ ] Confirm the minimum package set and obtain approval before adding PhpSpreadsheet/Laravel Excel and the S3 filesystem adapter.
- [ ] Implement private upload, checksum/deduplication, scan status, and immutable original workbook storage.
- [ ] Build workbook inspection for worksheet selection, used-range metadata, merged cells, dimensions, and safe preview generation.
- [ ] Define typed mapping DTOs and validators for timetable area, day columns, time rows, schedule-cell rendering, placeholders, and signatory fields.
    - **Progress:** mapping JSON can be stored on a template version, but no mapping contract or validator exists.
- [ ] Implement placeholder catalogs and context-aware placeholder resolution.
- [ ] Implement queued preview and export jobs that clone the source workbook, preserve formatting where practical, place schedule content deterministically, and write immutable output assets.
- [ ] Define explicit behavior for sessions crossing multiple template slots, collisions, merged cells, unsupported formulas, charts, macros, and external links.
- [ ] Build the upload, worksheet/range mapping, placeholder mapping, preview, validation, versioning, and reuse UI.
- [ ] Add golden-workbook regression tests for styles, merged cells, dimensions, logos, print settings, placeholders, and historical signatories.

**Exit criteria:** an organization can safely map a controlled `.xlsx` layout and reproduce a published timetable without attempting arbitrary spreadsheet inference.

## Phase 11 — Administration UX and operational workflows

- [x] Provide existing Inertia/Vue authentication, settings, organization switching, membership, and invitation interfaces.
- [ ] Add a permission-aware application navigation organized around Setup, Resources, Scheduling, Approvals, Templates, Reports, Billing, and Audit.
- [ ] Implement the academic, faculty, room, subject, offering, and availability pages described in earlier phases.
- [ ] Implement scheduling, version comparison, approvals, template mapping, export, billing, and audit pages described in earlier phases.
    - **Progress:** a typed timetable version status stamp now mirrors the backend draft-to-superseded state machine. Version screens, permission-aware actions, and comparison workflows remain.
- [ ] Standardize loading, empty, validation, conflict, authorization, entitlement, and failure states.
    - **Progress:** shared foundations now include a responsive `WorkspacePageHeader`, `WorkspaceState` for empty/loading/failure/access states, typed field-linked `ValidationSummary` corrections, and `SchedulingConflictList` grouping stable issue codes by operational category. Adoption across future domain pages remains.
- [ ] Add accessible table/grid navigation and responsive alternatives for scheduling workflows.
- [ ] Regenerate Wayfinder artifacts whenever backend routes change and keep generated imports out of hand-written URL strings.
- [ ] Add browser smoke tests for the critical owner, scheduler, approver, teacher/viewer, and multi-organization journeys.

**Exit criteria:** every MVP backend workflow has an authorized, accessible administration interface and browser coverage for its critical path.

## Phase 12 — Test, performance, and release hardening

- [ ] Maintain a green unit, feature, architecture, browser, lint, type, format, and build pipeline.
- [ ] Run domain tests on PostgreSQL in CI, including exclusion constraints, RLS, partial indexes, hierarchy concurrency, publication races, and advisory locks.
- [ ] Add contract tests for structured scheduling conflict responses and future solver boundaries.
- [ ] Add property-based or dataset coverage for time overlap, granularity, daylight-saving/timezone conversion, overnight rejection, and availability precedence.
- [ ] Add query-count tests for timetable views, permission resolution, hierarchy traversal, workload reports, and organization dashboards.
- [ ] Add load tests for large timetable reads, validation bursts, exports, and concurrent scheduling writes.
- [ ] Review indexes using representative PostgreSQL query plans and production-like volumes.
- [ ] Add dependency, secret, static-analysis, and upload-security checks to CI.
- [ ] Complete an authorization and tenant-isolation security review before production launch.

**Exit criteria:** release checks exercise production database behavior and demonstrate acceptable correctness, isolation, and response times.

## Phase 13 — Deployment and operations

- [ ] Define supported local, test, staging, and production environment configuration.
- [ ] Provision PostgreSQL, Redis, queue workers, scheduler, and private S3-compatible storage.
- [ ] Configure safe zero-downtime migrations and documented rollback/roll-forward procedures.
- [ ] Configure database backups, point-in-time recovery, object versioning/lifecycle rules, and restore drills.
- [ ] Add structured logs, correlation IDs, error tracking, queue monitoring, metrics, health checks, and alerts.
- [ ] Add CI/CD gates for tests, static checks, frontend build, migration validation, and deployment approval.
- [ ] Create runbooks for failed exports, stuck queues, tenant-context leaks, subscription webhook failures, publication races, and data restoration.
- [ ] Deploy to staging, perform representative tenant acceptance testing, and document the production readiness decision.

**Exit criteria:** the platform can be deployed, observed, backed up, restored, and operated safely by someone other than its original implementer.

## Phase 14 — Deferred automatic scheduling

These tasks are intentionally prepared for but excluded from the manual-scheduling MVP.

- [ ] Define a solver-neutral scheduling problem DTO, solution DTO, progress events, cancellation contract, and result explanation format.
- [ ] Add durable generation-run records with input snapshot/version, seed, status, progress, diagnostics, and output draft-version reference.
- [ ] Implement a queued Laravel solver adapter for a constrained initial problem size only after manual scheduling and constraint handlers are stable.
- [ ] Reuse the same hard/soft constraint semantics and validate every generated result through the canonical scheduling validator before persistence.
- [ ] Add deterministic seeds, cancellation, timeout, retry, and partial-failure behavior.
- [ ] Benchmark representative institutions and define the threshold for moving optimization to a Python/OR-Tools worker.
- [ ] If thresholds justify it, implement the external solver behind the existing port using a versioned contract and authenticated job transport.

**Exit criteria:** automatic generation creates a new draft version, never bypasses canonical validation, and can change implementation technology without changing the core domain.

## MVP boundary

The MVP should include Phases 0–6 plus the minimum approval, subscription, audit, Excel export, administration UI, testing, and deployment work required for a secure manual-scheduling product. Keep these outside the first launch unless a validated customer requirement changes priority:

- automatic optimization and OR-Tools integration;
- arbitrary spreadsheet understanding or formula execution;
- SSO, public API, SIS/LMS integrations, and webhooks beyond billing;
- advanced reporting and forecasting;
- simultaneous collaborative timetable editing;
- generalized custom rule scripting.

## Architectural risks to revisit during implementation

- **Tenant isolation has multiple layers.** Application scoping, tenant-safe references, and PostgreSQL RLS must agree; partial adoption can create a false sense of safety.
- **The universal hierarchy can become an unvalidated tree editor.** Allowed type edges, closure maintenance, presets, and cycle-safe moves are necessary before exposing it broadly.
- **Recurring schedules and dated exceptions complicate conflicts.** Treat the recurring pattern and exception ledger as one effective schedule in validation, exports, and reports.
- **Constraint configuration can become JSON business logic.** Store typed configuration only; keep executable behavior in versioned PHP handlers shared by manual and automatic scheduling.
- **Excel fidelity has hard limits.** Use a controlled mapping workflow, immutable originals, explicit unsupported-feature rules, and golden-workbook tests.
- **Approval history must be immutable.** Workflow versions and signatory snapshots cannot depend on mutable current membership/profile data.
- **Capability checks can drift.** Centralize entitlements and usage reservations before adding paid features; never branch on plan names.
- **Database portability is not a goal for scheduling correctness.** SQLite is useful for fast local tests, but PostgreSQL integration tests are mandatory for RLS, exclusion constraints, partial indexes, and advisory locks.
