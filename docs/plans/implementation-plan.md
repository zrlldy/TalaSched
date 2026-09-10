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
- [x] Run and record PHP formatting and static-analysis checks after the current domain work is stabilized.
    - **Verified:** `vendor/bin/pint --dirty --format agent` passes, and PHPStan passes with zero errors using `php -d memory_limit=-1 vendor/bin/phpstan analyse --debug --no-progress`. The default PHPStan memory limit is insufficient for this repository, while its parallel coordination socket remains unavailable in this sandbox (`EPERM`); the debug-mode verification is the reliable local check.

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
- [x] Audit every tenant table for composite tenant-safe foreign keys or equivalent application invariants.
    - **Verified:** tenant-owned pivot tables now carry explicit organization ownership, existing rows are backfilled and checked before constraints are applied, and all tenant-to-tenant references use composite organization-safe foreign keys. Schema-wide coverage verifies every tenant parent reference, a cross-organization assignment test proves database enforcement, the migration succeeds against the existing SQLite database, and the full Pest suite passes 150 tests and 747 assertions.
- [ ] Verify RLS policies, forced RLS behavior, connection-pool cleanup, and bypass roles against PostgreSQL in automated integration tests.
    - **Progress:** ADR 0003 formalizes memberships and invitations as identity control-plane exceptions. A dedicated PostgreSQL Pest suite enumerates domain tenant tables, checks forced fail-closed policies, tests missing and cross-organization context, verifies owner enforcement and application-role privileges, and exercises disconnect/reconnect cleanup. Tenant-scoped entitlement fixtures now establish `TenantContext`, and CI grants the bypass-only migrator role its narrow audit-ledger read used to prove global audit visibility without extending runtime access. The local PostgreSQL 18.6 suite recognizes the deliberate PostgreSQL 17 production-baseline mismatch while preserving a success assertion for the PostgreSQL 17 CI lane. Local verification confirms UTC session timezone, `public` schema, all 56 forced tenant RLS protections, all 56 expected tenant isolation policies, `btree_gist`, restricted application privileges, and no protected-table ownership by the runtime role. The GitHub Actions lane provisions separate owner, migrator, and application roles on PostgreSQL 17, with bypass reserved for controlled migrations and runtime ownership denied. The item remains incomplete until that PostgreSQL 17 CI lane or an equivalent production-like environment runs successfully.
- [ ] Configure and verify PostgreSQL as the supported production database.
    - **Progress:** Local development PostgreSQL verification is complete for connectivity, migrations, UTC timezone, `public` schema, all 56 forced tenant RLS protections and tenant policies, and `btree_gist` on PostgreSQL 18.6. ADR 0004 still defines PostgreSQL 17 as the production baseline with separate runtime, owner, and migrator roles. Laravel PostgreSQL connections now set application names, UTC session timezone, and certificate path options; production verification requires TLS unless explicitly disabled for ephemeral CI. `php artisan database:verify-production` continues to enforce the production baseline: PostgreSQL 17, restricted non-owner runtime role, no runtime `BYPASSRLS`, protected-table ownership by a no-login owner role, forced tenant RLS, tenant policies, `btree_gist`, schema, timezone, and TLS status. Production/CI PostgreSQL 17 verification and production role separation, TLS, ownership, and restricted runtime-role verification remain pending in the GitHub Actions lane or an equivalent provisioned production-like environment.
- [ ] Configure Redis for cache, sessions where selected, queues, throttling, and distributed locks.
    - **Progress:** Local development uses Laravel's built-in database-backed drivers: `CACHE_STORE=database`, `SESSION_DRIVER=database`, and `QUEUE_CONNECTION=database`. The required `cache`, `cache_locks`, `sessions`, `jobs`, `job_batches`, and `failed_jobs` tables exist in migrations. `infrastructure:verify-database-drivers` verifies database-backed cache, rate limiter, sessions, queue configuration, table presence, cache round trips, and cache locks. Focused tests prove database cache writes, database cache locks, database session configuration, database queue persistence, and the verifier command. Redis remains pending for production/scaling and must be provisioned and integrated separately before this item is complete.
- [ ] Configure private S3-compatible object storage and signed-download authorization.
    - **Progress:** Private artifact and signature disks now accept S3-compatible endpoint/path-style configuration while retaining local development/test storage. Signatory downloads use five-minute signed URLs, authenticated organization membership, and a second approval-management authorization check before streaming the private object; focused feature coverage passes. Production verification remains pending because this repository does not yet include the Flysystem S3 adapter or provisioned bucket credentials.
- [x] Hash organization invitation tokens at rest, add expiry/acceptance invariants, and avoid retaining reusable plaintext secrets.
    - **Verified:** Invitations now persist only SHA-256 token hashes, use UUID public route identifiers, normalize recipient emails, and retain plaintext tokens only for creation-time delivery. PostgreSQL check constraints enforce creation, expiry, and acceptance ordering; queued invitation notifications are encrypted. Invitation lifecycle, authentication handoff, frontend contracts, migration, and full Pest coverage pass locally (157 tests, 777 assertions). PostgreSQL-specific verification remains part of the pending PostgreSQL integration lane.
- [x] Add cross-tenant request, route-model-binding, nested-ID, direct-query, queued-job, and file-download isolation tests.
    - **Verified:** Tenant-prefixed requests, nested membership binding, scheduling public-ID/direct-query boundaries, and queued foreign-record selection with context cleanup remain covered. Private signatory downloads now reject a valid signed URL addressed through another organization with `404`, proving a privileged foreign organization cannot retrieve the file. Focused approval-controller coverage passes 7 tests and 80 assertions; the full suite passes 322 tests and 1,945 assertions.

**Exit criteria:** tenant context is explicit in every execution mode, PostgreSQL isolation is tested, and no user-controlled identifier can cross organization boundaries.

## Phase 2 — Roles, permissions, and policy enforcement

- [x] Create normalized role, permission, role-permission, and membership-role-assignment tables.
- [x] Define and seed the canonical permission catalog and immutable built-in organization roles.
    - **Verified:** Canonical organization permissions are upserted from the enum catalog, organization-scoped system roles are provisioned with synchronized role-permission pivots, and legacy owner/admin/member memberships receive normalized unscoped assignments. Organization creation, factories, and the repeatable seeder all use the same tenant-context-aware provisioner; focused authorization coverage and the full Pest suite pass (162 tests, 825 assertions).
- [x] Replace runtime checks against the legacy `organization_members.role` value with normalized role assignments.
    - **Verified:** `HasOrganizations`, organization policies, ownership checks, and minimum-role middleware now resolve authorization from tenant-context-aware normalized assignments and role permissions. Membership pivot saves and member/invitation lifecycle actions synchronize normalized assignments transactionally; the legacy role column remains a compatibility write/read-model field for existing invitation and Inertia contracts. Focused authorization coverage and the full Pest suite pass (163 tests, 828 assertions).
- [x] Support optional academic-unit scope on role assignments using the academic hierarchy closure table.
    - **Verified:** Added a read-only closure model and optional academic-unit context to normalized permission resolution. Unscoped assignments remain organization-wide; scoped assignments apply to their exact unit and closure descendants only, with explicit tenant ownership checks. Root, descendant, sibling, and cross-organization authorization tests pass, and the full Pest suite passes (164 tests, 833 assertions).
- [x] Implement a permission resolver with request-local caching and explicit cache invalidation.
    - **Verified:** Normalized organization and academic-unit permission checks now use a Laravel scoped resolver with lifecycle-local caching. Organization-wide provisioning invalidates cached decisions, direct assignment changes expose explicit user/scope and organization invalidation methods, and authorization tests verify cached results refresh after invalidation. The full Pest suite passes (164 tests, 834 assertions).
- [ ] Create policies for organizations, memberships, invitations, academic records, resources, schedules, versions, approvals, templates, subscriptions, and audit records.
    - **Progress:** Added auto-discovered tenant policies for current organization, membership, invitation, academic, resource, catalog, timetable, version, schedule, custom role, approval signatory-profile, template/file/export, and audit-event models. Audit reads require normalized organization-update permission, and its policy refuses all create, update, and delete operations. Signatory reads and mutations require both normalized scheduling management permission and the `approval_workflows` entitlement; the signatory list, signed download, and mutation requests enforce that policy while direct service calls retain the same domain guard. Workflow tables and subscriptions remain pending until their schema-only areas have models and command surfaces. Focused audit policy/controller coverage passes 4 tests and 72 assertions; PHPStan passes with zero errors.
- [x] Prevent privilege escalation, owner removal, last-owner loss, and unauthorized role delegation.
    - **Verified:** Organization deletion now requires both the normalized delete permission and the explicit `owner_user_id`; owner memberships and owner role assignments cannot be removed or granted through model writes, owner invitations are rejected at request and model boundaries, direct owner identity changes require a matching owner membership, and membership role updates authorize the target role's permission set. `TransferOrganizationOwnership` authorizes the explicit owner, locks the organization and memberships in a retried tenant transaction, requires an existing member target, synchronizes compatibility and normalized roles, and records the before/after audit event. The full Pest suite passes (193 tests, 967 assertions).
- [x] Gate custom-role administration behind the subscription capability system.
    - **Verified:** `RolePolicy` allows organization members to view roles, but requires normalized `UpdateMember` permission and an active `custom_roles` entitlement for custom-role creation, updates, and deletion. System roles remain immutable through the policy; focused policy tests and the full suite pass.
- [x] Build organization role and member administration pages with clear scope and capability feedback.
    - **Verified:** Organization settings now expose member administration alongside built-in and custom role definitions. Entitled administrators can create, edit, and delete unassigned custom roles with selected canonical permissions; system roles and assigned roles are protected, and users without the capability receive explicit upgrade feedback. Route/Inertia tests, Vue type checking, lint, production build, and the full Pest suite pass.
- [x] Add authorization matrix tests for built-in roles, custom roles, academic-unit scope, and multiple-organization users.
    - **Verified:** Built-in owner/admin/member decisions are compared against the canonical permission matrix; custom-role assignments resolve only selected permissions; existing root, descendant, sibling, and foreign academic-unit scope coverage remains green; and a user with memberships in multiple organizations receives independent role and permission decisions. The authorization suite passes 31 tests and 180 assertions, and the full suite passes 205 tests and 1,043 assertions.

**Exit criteria:** all protected operations use policies/permissions rather than fixed role comparisons or controller-only checks.

## Phase 3 — Academic structure and calendars

- [x] Create schema for academic years, periods, unit types, allowed type edges, units, hierarchy closure, calendars, calendar exceptions, and student groups.
- [x] Add initial Eloquent models and enums for the principal academic entities.
- [x] Complete model relationships, casts, tenant invariants, factories, and deletion rules.
    - **Verified:** Academic years, periods, unit types, units, closure rows, and student groups now expose their tenant-safe relationship graph and typed attributes. Immutable organization ownership, cross-tenant parent checks, academic/unit date boundaries, co-located factory states, soft deletion, and restrictive required-parent deletion behavior are covered by focused academic model tests; the full suite passes 209 tests and 1,072 assertions.
- [x] Implement the academic hierarchy service to create, move, archive, and query units transactionally.
    - **Verified:** `AcademicHierarchyService` runs under `TenantContext` and retried transactions, validates organization-owned types and configured parent edges, creates and rewrites closure rows for subtree moves, rejects descendant cycles, guards archive operations, and queries roots with closure depth. Focused hierarchy coverage and the full suite pass (213 tests, 1,090 assertions).
- [x] Maintain closure-table rows and reject cycles under concurrency.
    - **Verified:** Create and move operations maintain closure rows and use ordered row locks. The forked PostgreSQL integration test exercises concurrent child creation and verifies complete, unique closure paths inside explicit tenant context; it passes 1 test and 9 assertions on the isolated local PostgreSQL 18.6 database and is wired into `phpunit.postgresql.xml` and CI.
- [x] Validate allowed parent/child unit-type edges while keeping unit types configurable per organization.
    - **Verified:** `AcademicHierarchyService` validates organization-local configured type edges for create and move operations while allowing each organization to define its own hierarchy; focused valid/invalid edge tests and the full suite pass (213 tests, 1,090 assertions).
- [x] Add optional preschool, K–12, senior-high, university, and training-center presets that create ordinary configurable units rather than special-case schema.
    - **Verified:** `AcademicHierarchyPresetService` provisions organization-local three-level unit types, configured edges, and editable sample paths for preschool, K-12, senior high, university, and training-center structures. Stable codes make application idempotent while preserving administrator edits; focused coverage and the full suite pass (220 tests, 1,127 assertions).
- [x] Implement academic year and configurable period lifecycle rules without assuming semesters.
    - **Verified:** `AcademicYearLifecycleService` creates draft years, permits configurable non-overlapping period kinds, requires contiguous period sequences for activation, prevents overlapping active years, and supports one-way close transitions. Focused lifecycle coverage, scoped static analysis, and the full suite pass (223 tests, 1,138 assertions).
- [x] Implement calendars, operating hours, holidays, blocked dates, and exceptional teaching dates.
    - **Verified:** Tenant-safe academic calendar models and `AcademicCalendarService` now manage one operating-hours row per period/weekday and idempotent date exceptions for holidays, blocked dates, and teaching windows. Date bounds, minute windows, full-day block semantics, closed-year protection, relationships, factories, focused coverage, and the full suite pass (226 tests, 1,157 assertions).
- [x] Implement student-group membership in the hierarchy and active-date handling.
    - **Verified:** Student groups now have tenant-safe optional active dates bounded by their academic year, and `StudentGroupService` transactionally assigns units, enrolls or unenrolls groups from matching periods idempotently, rejects inactive or foreign-period membership, and protects closed years. Migration, focused coverage, scoped static analysis, and the full suite pass (228 tests, 1,173 assertions).
- [x] Add actions, requests, policies, routes, and Inertia/Vue administration pages for academic setup.
    - **Verified:** Organization-scoped public-ID routes and policies now expose year/period lifecycle, configurable hierarchy units, operating hours, calendar exceptions, and student-group dates/unit/period membership through the `academic/Setup` Inertia page. Wayfinder bindings, focused controller coverage, Vue type-checking/linting, production build, static analysis, and the full suite pass (230 tests, 1,224 assertions).
- [x] Add tests for arbitrary valid hierarchies, invalid edges, cycle prevention, period boundaries, and tenant isolation.
    - **Verified:** Focused academic tests now cover a four-level arbitrary configured chain in addition to invalid parent edges, descendant-cycle moves, period date/sequence boundaries, foreign-year/unit references, and tenant-isolated queries. The full suite passes (231 tests, 1,227 assertions).

**Exit criteria:** an organization can configure each target institution structure and calendar without code or schema changes.

## Phase 4 — Faculty, rooms, resources, subjects, and offerings

- [x] Create the scheduling-resource backbone and schemas for faculty, rooms, features, availability, subjects, subject components, offerings, and student groups.
- [x] Model teachers, rooms, and groups as schedulable resources suitable for one canonical conflict mechanism.
- [x] Complete resource model relationships, value objects, factories, archival behavior, and tenant-safe constraints.
    - **Verified:** Resource, faculty, room, building, feature, availability, subject, component, and offering models now expose typed tenant-safe relationships, enum/value casts, immutable ownership, parent organization checks, soft-archive behavior, and usable co-located factories. Persisted graph, archival, cross-tenant, focused scheduling, Pint, and scoped PHPStan checks pass (15 tests, 123 assertions).
- [x] Implement faculty profiles, employment metadata, department links, daily/weekly load limits, availability, and preferences.
    - **Verified:** Added `FacultyProfileService` for tenant-context-aware profile creation/update, employment metadata and load-limit validation, primary academic-unit assignment, and resource-scoped availability/preference rules. Focused service coverage passes 4 tests and 60 assertions; the full suite passes 235 tests and 1,287 assertions. Administration workflow remains in the dedicated actions/routes/UI item below.
- [x] Implement campus/building/room management, configurable room types, capacities, features, and availability.
    - **Verified:** Added `ResourceManagementService` for tenant-context-aware campus-linked buildings, configurable room types/features, atomic room plus scheduling-resource creation, feature quantities, and archive/restore synchronization with the resource active flag. Existing resource availability rules provide the room/faculty availability model; focused resource coverage and the full suite pass (237 tests, 1,311 assertions). Precedence/overlap semantics remain in the dedicated availability task.
- [x] Implement subject/course catalogs with lecture/laboratory components, units, required hours, default duration, and room requirements.
    - **Verified:** Added `SubjectCatalogService` for tenant-context-aware subject CRUD/archive/restore, typed catalog components with weekly/session/duration defaults, and explicit tenant-safe room-type/feature requirement replacement. Component history survives subject archival; focused catalog coverage and the full suite pass (239 tests, 1,335 assertions).
- [x] Implement period-specific offerings that associate subjects, groups, eligible instructors, required sessions, and delivery requirements without permanently assigning teachers to subjects.
    - **Verified:** Added `SubjectOfferingService` to validate period/group academic-year integrity, snapshot subject component defaults and room/feature requirements into period offerings, manage active eligible faculty through tenant-keyed instructor pivots, and keep snapshots independent from later catalog edits. Focused offering coverage and the full suite pass (245 tests, 1,374 assertions).
- [x] Define precedence and overlap validation for resource availability rules.
    - **Verified:** Added `ResourceAvailabilityResolver` with calendar baseline, period-specific-over-global hard available windows, additive hard unavailable blocks, effective-date filtering, and soft preferred/avoid warnings. Scheduling validation now blocks only hard issues and exposes soft warnings; focused coverage and the full suite pass (245 tests, 1,374 assertions). Administrative resource management remains in the dedicated actions/routes/UI item.
- [x] Build actions, policies, routes, and Inertia/Vue administration pages for faculty, rooms, features, subjects, offerings, and availability.
    - **Verified:** Added organization-authorized resource/catalog form requests, resource and metadata policies, tenant-context availability creation, Wayfinder routes, and the `resources/Setup` Inertia/Vue workspace. The workflow exposes public IDs and organization-local codes only; focused setup coverage passes 2 tests and 59 assertions, the full suite passes 247 tests and 1,433 assertions, frontend type/lint/build checks pass, and scoped PHPStan is clean.
    - **Verified fix (2026-09-09):** Faculty creation normalizes validated browser numeric strings before the strict domain service and permits either optional load limit to be omitted. A labelled Teaching load limits section explains minutes, provides examples, and links validation to the daily/weekly inputs; directory rows show both limits and successful creation resets the form. Regression coverage reproduces the original rejection and checks blank, omitted, single, invalid, and inconsistent limits. Focused controller/service tests pass 16 tests; desktop/mobile creation and the existing resource-section browser journey pass. PHPStan, TypeScript, ESLint, Pint, Prettier, and the production build pass.
- [x] Recover offerings created before subject teaching requirements and expose saved setup records clearly.
    - **Verified (2026-09-10):** Faculty, room, subject, and availability directories provide searchable tables with card layouts and responsive mobile cards. Teacher creation uses an accessible modal that preserves drafts on close, resets after success, and keeps optional teaching limits visible. Offering cards guide users from missing subject requirements through an audited, repeat-safe action that adds only missing component snapshots, then instructor assignment. The timetable composer explains incomplete offerings and missing rooms; date and committed-version views explain why editing is unavailable. Twenty-five focused feature/service tests and ten browser journeys pass, including desktop/mobile recovery through a saved timetable class, teacher validation, directory layouts, dark mode, and read-only controls. Screenshots were inspected; PHPStan, TypeScript, ESLint, Pint, Prettier, and the production build pass.
- [x] Add import preparation points for bulk faculty, room, subject, and offering data without implementing a generic importer prematurely.
    - **Verified:** Added explicit `FacultyImportRow`, `RoomImportRow`, `SubjectImportRow`, and `OfferingImportRow` contracts with fixed column layouts and scalar/enum normalization. No file parser, queue, or generic importer was introduced; focused contract coverage passes 3 tests and 23 assertions.
- [x] Add CRUD, validation, authorization, availability, and cross-tenant tests.
    - **Verified:** Existing service coverage exercises update/archive/restore, relationship replacement, eligibility, and availability behavior; added setup-controller validation/isolation cases and generic resource availability service coverage. Focused resource tests pass 5 tests and 81 assertions; full-suite and static checks are run in final verification.

**Exit criteria:** schedulers can prepare all inputs required for manual scheduling through authorized administration workflows.

## Phase 5 — Manual scheduling and conflict prevention

- [x] Create canonical timetable, version, schedule-entry, entry-resource, reservation, and dated-exception schema.
- [x] Add a PostgreSQL exclusion constraint for overlapping reservations and a partial uniqueness rule for a published version when PostgreSQL is used.
- [x] Implement JSON endpoints to validate and create recurring schedule entries.
- [x] Return structured conflict issues for the current validator checks.
- [x] Add initial tests for valid creation, resource overlap, availability, room requirements, and faculty load.
- [x] Refactor the monolithic validator into a registry of typed hard- and soft-constraint handlers backed by database configuration.
    - **Verified:** `ValidateScheduleEntry` now builds an immutable prefetched scheduling context and delegates to registered typed handlers. Canonical constraint definitions are seeded, organization/period configuration selects or disables non-mandatory handlers, mandatory handlers cannot be downgraded, and structured hard/soft issues remain compatible with the existing API. Relationship foreign-key inference is explicit for `constraint_definition_id`; focused scheduling coverage passes 15 tests and 76 assertions, the full suite passes 257 tests and 1,488 assertions, and scoped PHPStan/Pint checks pass.
- [x] Add missing hard checks: calendar operating hours, blocked dates, positive available-window semantics, room features, instructor eligibility, offering hour/session fulfillment, exception dates, effective date ranges, and organization-wide blocked times.
    - **Verified:** The canonical validator now supports optional occurrence dates for exact calendar exception and resource effective-range evaluation, rejects out-of-period or weekday-mismatched dates, honors exceptional teaching windows, applies conservative full-period checks to recurring availability rules, and evaluates validated organization-wide blocked-time windows from constraint configuration. Focused scheduling coverage passes 24 tests and 126 assertions; the full suite passes 273 tests and 1,609 assertions; scoped PHPStan, Pint, and diff checks pass.
- [x] Add configurable soft-constraint evaluation and return warnings/scores separately from hard failures.
    - **Verified:** Constraint results now preserve the legacy combined `issues` list while exposing separate `hard_issues`, `warnings`, and a configuration-weighted warning `score` from the validation endpoint. Schedule creation continues to reject only hard issues; focused scheduling coverage passes 15 tests and 79 assertions, the full suite passes 257 tests and 1,491 assertions, and scoped PHPStan/Pint checks pass.
- [x] Acquire deterministic PostgreSQL advisory locks for all affected resources before conflict validation and insertion.
    - **Verified:** authoritative schedule creation acquires transaction-scoped PostgreSQL locks for the organization, timetable version, and deduplicated numerically sorted resource IDs before row locking and canonical validation. Non-PostgreSQL test connections safely no-op; lock-name determinism is covered by a unit test, the local PostgreSQL advisory-lock function is available, and the full suite passes 258 tests and 1,492 assertions.
- [x] Translate database exclusion/uniqueness violations into the same structured conflict response used by preflight validation.
    - **Verified:** schedule creation catches only PostgreSQL exclusion violations and schedule-reservation/assignment uniqueness violations after transaction rollback, translates them into the existing `schedule_conflict` contract with affected resource identities, and rethrows unrelated query errors. Focused conflict/scheduling coverage passes 17 tests and 84 assertions, the full suite passes 260 tests and 1,497 assertions, and scoped PHPStan/Pint checks pass.
- [x] Add update, move, resize, and delete actions with optimistic locking and full revalidation.
    - **Verified:** added tenant-scoped PATCH and DELETE scheduling routes, validated mutation requests, atomic projection rebuild/removal actions, compare-and-swap `lock_version` checks, stale-write responses, version editability guards, advisory locks, and canonical hard-constraint revalidation excluding the entry being updated. Focused scheduling coverage passes 28 tests and 128 assertions, frontend type/lint checks pass, the full suite passes 263 tests and 1,515 assertions, and scoped PHPStan/Pint checks pass.
- [x] Implement per-date cancellation, replacement, and room/teacher substitution through schedule exceptions.
    - **Verified:** Added tenant-scoped schedule-exception command handling for cancellation, date rescheduling, and validated teacher/room replacement without mutating recurring entries. Exceptions enforce academic-period and recurring-weekday bounds, use date/resource advisory locks, expose public-ID JSON contracts, and focused scheduling coverage passes 21 tests and 118 assertions with scoped PHPStan/Pint checks.
- [x] Build canonical read models/queries for teacher, student-group, room, unit, and organization timetable views without duplicating schedule records.
    - **Verified:** Added a tenant-scoped TimetableViewQuery and typed filter/data contracts covering organization, teacher, student-group, room, and academic-unit projections from one recurring-entry source. Public-ID JSON output includes offering/resource context and effective dated cancellation/rescheduling fields without duplicating schedule records; focused read coverage passes 3 tests and 44 assertions, the scheduling suite passes 34 tests and 193 assertions, the full suite passes 269 tests and 1,580 assertions, and Wayfinder/type/lint checks pass.
- [x] Build the Inertia/Vue timetable grid, filters, entry editor, conflict panel, and accessible non-grid view.
    - **Verified:** Added a tenant-scoped Inertia workspace backed by the canonical timetable query, URL-shareable scope/version/resource/unit/date filters, a responsive weekly board with mobile agenda, accessible text-first agenda, public-ID entry inspector/editor, validation conflict panel, and read-only dated projections. Focused scheduling coverage passes 35 tests and 214 assertions, the full suite passes 270 tests and 1,601 assertions, and Wayfinder, type-check, lint, production build, PHPStan, Pint, and diff checks pass.
- [x] Add PostgreSQL concurrency tests proving simultaneous requests cannot double-book teachers, rooms, or groups.
    - **Verified:** Fork/barrier integration coverage proves isolated shared teacher, room, and student-group races commit exactly one entry, return a structured `resource_overlap` conflict for the losing request, and leave no partial reservations. The hierarchy closure concurrency test also passes inside explicit tenant context on local PostgreSQL 18.6; the PostgreSQL integration suite passes all non-version-gate tests.
- [x] Add policy, entitlement, audit, and tenant-isolation coverage to every scheduling mutation.
    - **Verified:** Scheduling mutations require the organization scheduling permission and an active `manual_scheduling` entitlement, record actor/subject-scoped create, update, delete, and exception audit events transactionally, and keep update/delete/exception lookups tenant-scoped. Focused authorization and scheduling coverage passes 48 tests and 250 assertions; the full suite passes 276 tests and 1,632 assertions; scoped PHPStan, Pint, and diff checks pass.

**Exit criteria:** authorized schedulers can safely create and maintain a timetable manually, and the database remains the final guard against double booking.

## Phase 6 — Timetable versioning and publication

- [x] Model immutable timetable versions with draft, review, approved, published, and superseded states.
- [x] Implement initial clone and publish actions.
- [x] Add passing basic clone and publish tests.
- [x] Clone schedule exceptions and all version-owned metadata, not only entries/resources/reservations.
    - **Verified:** Timetable version cloning now preserves entry logical lineage and copies dated exception fields plus exception-resource pivots into the new draft with fresh public IDs; approval instances and export runs remain historical source-version records. Focused versioning coverage passes 4 tests and 20 assertions; the full suite passes 277 tests and 1,643 assertions; scoped PHPStan, Pint, and diff checks pass.
- [x] Add version comparison by stable entry lineage and present added, removed, moved, reassigned, and changed entries.
    - **Verified:** Added an organization-scoped JSON comparison endpoint that validates both public version IDs against one timetable, keys snapshots by stable `logical_id`, and reports added, removed, moved, reassigned, and content-changed entries with before/after data. Focused versioning coverage passes 5 tests and 35 assertions; the full suite passes 278 tests and 1,658 assertions; scoped PHPStan, Pint, and diff checks pass.
- [x] Implement rollback as cloning an older version into a new draft; never mutate published history.
    - **Verified:** Added an authorized rollback action for published and superseded sources that reuses the transactional clone path, creates a new draft with `based_on_version_id`, and rejects editable sources without changing version history. Focused versioning and authorization coverage passes 25 tests and 139 assertions; the full suite passes 280 tests and 1,670 assertions; scoped PHPStan, Pint, and diff checks pass.
- [x] Run complete hard-constraint validation immediately before approval completion and publication.
    - **Verified:** `ValidateTimetableVersion` now runs inside both the locked approval-completion and publication transactions, preserving structured entry identifiers and rejecting invalid versions before committing state or superseding history. Focused versioning/approval coverage passes 29 tests and 166 assertions; the full suite passes 284 tests and 1,697 assertions; scoped PHPStan and Pint pass.
- [ ] Add state-machine transition guards, authorization, capabilities, audit events, domain events, and transactional outbox handling where external work follows publication.
    - **Progress:** Version clone, rollback, submit, publish, and approval-decision actions now enforce locked state transitions, capability-gated policy abilities, idempotent approval decisions, and transactional audit records. Domain events and an outbox remain pending because the repository has no publication side-effect consumer or outbox schema to integrate without speculative infrastructure.
- [x] Add version list, clone, compare, submit, publish, supersede, and rollback UI.
    - **Verified:** Added a capability-aware version ledger with Wayfinder-backed clone, compare, submit, publish, and rollback actions, workflow selection, transition errors, snapshot metadata, and accessible responsive states. Focused versioning coverage passes 10 tests and 67 assertions; the full suite passes 305 tests and 1,806 assertions; Vue type checking, ESLint, Prettier, and the production build pass.
- [x] Add race-condition tests for concurrent publication and immutable-history tests.
    - **Verified:** Added a forked PostgreSQL publication race test proving that concurrent approved-version publications serialize behind the timetable lock and leave exactly one published head plus one superseded historical version. Existing clone and rollback tests verify fresh snapshot identifiers and unchanged historical state; the focused PostgreSQL race passes 1 test and 8 assertions, and the full SQLite suite remains green.

**Exit criteria:** published versions cannot be edited, historical versions remain reproducible, and every transition is authorized and audited.

## Phase 7 — Approval workflows and signatories

- [x] Create workflow, workflow-version, step, approval-instance, approval-action, and signatory-profile schema.
- [x] Implement an initial action that snapshots workflow steps when a timetable version is submitted.
    - **Verified:** Submission snapshots ordered selector metadata and role codes into each approval instance step, and blocks duplicate active instances while allowing resubmission after requested changes.
- [x] Decide and document whether step eligibility needs a dedicated `approval_step_members` relation in addition to role/permission selectors.
    - **Verified:** MVP eligibility uses immutable permission and organization-role selector snapshots; a named-membership relation is deferred until named approver administration is implemented.
- [x] Implement versioned workflow authoring, validation, activation, and retirement.
    - **Verified:** Added tenant- and `approval_workflows`-capability-gated create, activate, and retire actions. New workflows remain inactive until a version is activated; workflow steps enforce contiguous sequences, valid selectors, positive approval counts, organization-local roles, and immutable activated versions; submission rejects draft or retired workflow versions. Focused workflow coverage passes 5 tests and 18 assertions; the full suite passes 297 tests and 1,764 assertions; scoped PHPStan and Pint pass.
- [x] Implement approve, reject, request-changes, cancel, and resubmit actions with sequential step advancement.
    - **Verified:** `DecideTimetableApproval` locks the instance and active step, enforces selector eligibility and self-approval rules, appends idempotent signatory decisions, advances ordered steps, returns requested-change versions to editable state, and completes approval only after hard validation; focused approval coverage passes 4 tests and 29 assertions.
- [ ] Resolve eligible approvers through organization roles, academic-unit scope, named memberships, or permissions.
    - **Progress:** Permission and organization-role selectors now enforce fixed workflow-step academic-unit targets through public-ID authoring, immutable instance snapshots, and ancestor-aware hierarchy resolution. Focused approval coverage passes 13 tests and 67 assertions; the full suite passes 309 tests and 1,826 assertions. Named-membership selectors remain pending with the deferred `approval_step_members` decision.
- [x] Configure separation-of-duties and self-approval rules per workflow.
    - **Verified:** Added immutable `require_distinct_approvers` configuration to workflow versions. Decision actions now enforce distinct actors across sequential steps while preserving per-step self-approval, selector eligibility, cancellation, and idempotency behavior. Focused approval coverage passes 10 tests and 53 assertions; the full suite passes 306 tests and 1,812 assertions; Pint, PHPStan, and diff checks pass.
- [x] Complete approval by transitioning the timetable version to approved only after all required steps succeed.
    - **Verified:** Final approval locks and validates the version before committing `approved`; sequential-step, invalid-version rollback, idempotency, requested-changes resubmission, and signatory snapshot coverage passes 4 tests and 29 assertions.
- [x] Implement signatory profile validity periods and private signature-image assets.
    - **Verified:** Added tenant- and approval-capability-authorized signatory profile creation/update through `SignatoryProfileService`, inclusive validity-window invariants, organization/member/unit ownership checks, a dedicated private signatures disk, MIME/extension/size/dimension validation, generated asset paths, SHA-256 checksums, replacement cleanup after commit, and audit events. Approval decisions ignore profiles outside their validity window; focused signatory coverage passes 5 tests and 33 assertions, combined signatory/approval coverage passes 13 tests and 81 assertions, and the full suite passes 315 tests and 1,865 assertions.
- [x] Snapshot signatory name, position, organization unit, label, decision, and signature asset at approval time so history remains stable.
    - **Verified:** Approval decisions copy the eligible signatory profile's name, position, academic-unit label, private asset coordinates/checksum, decision, and comment into append-only action rows with idempotency keys; focused approval coverage verifies the historical snapshot.
- [x] Build workflow designer, approval inbox, decision forms, status timeline, and signatory administration UI.
    - **Verified:** Added organization-scoped Inertia workflow designer, approval inbox with immutable status timeline and decision forms, and private signatory administration with validity dates and image uploads. Backend props and actions use public identifiers; focused UI/controller coverage passes 5 tests and 68 assertions, TypeScript and ESLint checks pass, and the production frontend build passes.
- [ ] Add authorization, replay/idempotency, concurrent-action, historical-snapshot, and workflow-version tests.
    - **Progress:** Authorization, idempotency, invalid-version rollback, sequential advancement, resubmission, workflow activation, retirement, signatory snapshot, and controller contract coverage pass locally. Historical workflow-version coverage now proves that an in-flight instance retains its submitted eligibility selector after a later activated version changes approver roles. The focused approval lane passes 21 tests and 153 assertions; the full suite passes 323 tests and 1,958 assertions. A PostgreSQL-gated concurrent decision lock-race regression exists; PostgreSQL execution remains pending.

**Exit criteria:** organizations can configure different approval chains and published outputs preserve the exact historical signatories.

## Phase 8 — Subscriptions, capabilities, and limits

- [x] Create plan, capability, plan-capability, organization-subscription, override, and usage schema.
- [x] Complete and verify the entitlement resolver.
    - **Verified:** `EntitlementService` resolves active trialing/active plan values, applies non-expired organization overrides first, returns typed boolean/integer values, and denies missing or expired entitlements. Focused subscription tests and the full suite pass.
- [x] Define capability metadata, value types, defaults, and seed Starter, Professional, and Enterprise plan fixtures.
    - **Verified:** `SubscriptionCatalogSeeder` idempotently seeds capability metadata and the three built-in plan tiers inside a transaction, with representative boolean and integer values covered by focused tests.
- [x] Define subscription lifecycle semantics for trialing, active, grace period, past due, canceled, and expired states.
    - **Verified:** Added the typed `SubscriptionStatus` vocabulary and `grace_ends_at` persistence. `EntitlementService` now centrally evaluates trial, billing, grace, past-due, cancellation, and expiry windows without plan-name checks; focused entitlement coverage passes 5 tests and 23 assertions, and the full suite passes 297 tests and 1,764 assertions.
- [ ] Implement centralized capability and numeric-limit checks through policies, middleware, domain guards, and reusable UI props.
    - **Progress:** `CapabilityGuard` now centralizes capability checks, numeric capacity assertions, and the complete typed entitlement map. Existing scheduling, versioning, custom-role, and approval authorization paths use the guard, and `HandleInertiaRequests` shares entitlements for UI gating. New organizations receive an active Starter subscription transactionally; legacy factory/fixture organizations without subscription metadata remain compatible until a backfill is defined.
- [ ] Implement transaction-safe usage reservations for limits such as members and active timetables.
    - **Progress:** Added `UsageService` with tenant-scoped lifetime capacity counters that initialize from existing member/timetable rows, lock before checking entitlements, retry deadlocks, reject over-limit reservations, prevent underflow, and roll back with the surrounding transaction. Organization creation bootstraps subscriptions, invitation acceptance reserves member capacity, and member removal/leave release it under organization locks; focused lifecycle coverage and the full suite pass 303 tests and 1,788 assertions. Timetable creation/removal command boundaries do not exist yet, so active-timetable wiring remains pending.
- [x] Add a billing-provider port and webhook idempotency ledger while keeping the initial provider optional.
    - **Verified:** Added the provider-neutral `BillingProvider` contract and immutable `BillingWebhook` payload, plus a global replay-safe ledger with unique provider/event identity, canonical payload-hash conflict detection, processing leases, retryable failures, and explicit completion transitions. No provider or payment collection is bound; focused billing coverage passes 4 tests and 16 assertions, and the full suite passes 303 tests and 1,788 assertions.
- [ ] Integrate capability checks for automatic scheduling, Excel templates, approval workflows, custom roles, multi-campus, API access, and audit features.
    - **Progress:** Manual scheduling, timetable versioning, approval workflows, and custom roles now enforce capabilities through domain policies/actions. Automatic scheduling, Excel templates, multi-campus, API access, and audit-feature entitlements remain pending with their command surfaces.
- [x] Build plan/usage/subscription administration pages and disabled-feature upgrade messaging.
    - **Verified:** Added an organization-update-authorized, read-only plan and usage page that exposes the server-resolved subscription lifecycle, plan capabilities, and numeric capacity while keeping billing-provider data private. The sidebar shows it only to authorized administrators, unavailable capabilities explain that self-service plan changes are intentionally not configured, and member access is forbidden. Focused controller coverage passes 2 tests and 23 assertions; PHPStan, TypeScript, ESLint, Prettier, and the production build pass.
- [x] Add tests for overrides, downgrades below current usage, concurrent limit consumption, grace periods, and webhook replay.
    - **Verified:** Entitlement coverage proves override precedence and lifecycle/grace semantics, billing coverage proves duplicate webhook claims remain replay-safe, and usage coverage proves a downgrade never erases recorded usage but blocks further consumption. A forked PostgreSQL capacity race proves exactly one reservation can consume the final member slot. Focused usage coverage passes 5 tests and 18 assertions; the PostgreSQL concurrency regression passes 1 test and 7 assertions.

**Exit criteria:** no product code compares plan names directly, and all features and limits are enforced centrally on the server.

## Phase 9 — Audit, security, and data governance

- [x] Create an append-only audit-event schema and an initial `AuditLogger` abstraction.
- [x] Integrate audit logging into organization, membership, academic, resource, scheduling, version, approval, subscription, template, export, and security-sensitive actions.
    - **Verified:** Version lifecycle, approval submission/decision, organization lifecycle, subscription provisioning and webhook ledger transitions, membership changes, invitation lifecycle, custom-role administration, all implemented academic/resource/catalog commands, template upload/version/activation/export, file-asset scan outcomes, account registration, profile updates, self-deletion, password resets, and authenticated password changes record transactionally. Profile records contain only boolean change summaries; password records deliberately contain no credential or token payload. Focused billing-webhook and audit coverage passes 6 tests and 27 assertions; PHPStan passes with zero errors.
- [x] Capture actor, organization, target, request metadata, before/after summaries, correlation ID, and impersonation context without storing secrets.
    - **Verified:** `AuditLogger` records client IP/user agent, reuses the active request correlation ID, prefers public subject identifiers, and records the active `impersonator_user_id` context when a delegated-access flow establishes one. Organization-less account and provider-webhook events use a scoped PostgreSQL insert policy and preserve historical actor identifiers after account deletion. It does not log request bodies, headers, credentials, tokens, webhook payloads, or provider errors. Focused audit metadata coverage passes 3 tests and 15 assertions; PHPStan passes with zero errors.
- [ ] Define sensitive-field redaction, retention, export, and deletion policies.
    - **Progress:** Audit actions exclude request bodies, headers, credentials, tokens, provider payloads, and provider errors. Exact retention periods for audit events, uploaded templates, generated exports, and deleted-organization data are explicitly unresolved in `docs/architecture/REVIEW.md`; no destructive purge/export policy is inferred without an owner decision.
- [ ] Add immutable-audit behavior and tests for critical actions.
    - **Progress:** Added SQLite and PostgreSQL database triggers that reject audit-event updates and deletes. Account deletion preserves historical audit actor/impersonator identifiers without attempting a blocked `ON DELETE SET NULL` mutation, and SQLite enforcement now covers ordinary, account, and critical billing-webhook audit events. PostgreSQL policy/immutability validation remains pending because the local test database migration ledger is stale and attempts to replay older owner-only migrations before this trigger migration.
- [x] Enforce MIME, extension, ZIP-entry, decompression-size, macro, and formula/external-link policies for uploaded workbooks and signature assets.
    - **Verified:** Signature uploads validate decoded type, extension, MIME type, size, and a 4096-pixel maximum. Workbook uploads validate extension, detected MIME type, compressed size, XLSX structure, safe entry paths/counts, per-entry and total expansion limits, and compression ratio before private persistence. Macros and external links are rejected, while the same archive policy runs again before clean-asset inspection and export. Mapped formulas fail deterministically and unmapped formulas remain intact. Focused workbook/template coverage passes 15 tests and 116 assertions; PHPStan passes with zero errors.
- [x] Add malware-scanning integration points and quarantine states for uploaded assets.
    - **Verified:** Pending file assets dispatch encrypted, unique, tenant-scoped `ScanFileAsset` jobs after commit. The scanner depends on a provider-neutral `MalwareScanner` port, with a fail-closed disabled default and an opt-in ClamAV INSTREAM adapter. Clean results unlock processing; infected assets remain private and become quarantined; exhausted scanner jobs become failed. Focused coverage passes 10 tests and 42 assertions; PHPStan passes with zero errors. Production ClamAV provisioning and scanner-health monitoring remain Phase 13 operational work.
- [x] Encrypt or otherwise protect sensitive signature assets and issue short-lived authorized downloads.
    - **Verified:** Signature images live on a non-public, non-framework-served disk with generated paths and checksums. Five-minute signed download URLs additionally require authentication, organization membership, approval-management authorization, and organization-scoped profile binding; focused controller coverage passes 14 tests and 124 assertions.
- [x] Add rate limits for invitations, schedule validation, exports, uploads, and future generation jobs.
    - **Verified:** Organization-and-actor-scoped limits protect invitation creation/cancellation (10/minute), invitation responses (10/minute), schedule validation (30/minute), uploads (10/minute), and template exports (5/minute). `GenerateTemplateExport` also uses an organization-scoped queue limiter (10/minute), so queued rendering cannot bypass the command limit. Focused coverage verifies invitation, schedule-validation, export-request, and queue-limiter behavior.

**Exit criteria:** critical changes are attributable, sensitive artifacts are private, and untrusted uploads cannot reach processing or download paths unchecked.

## Phase 10 — Excel template mapping and export

- [x] Create file-asset, Excel-template, template-version, and export-run schema.
- [x] Add tenant-scoped Excel template, immutable version, and export-run application models, factories, policies, and public identifiers.
    - **Verified:** Template versions require a clean same-tenant source asset and preserve mapping inputs. Export runs enforce tenant-safe references, immutable request inputs, and a pending-to-terminal artifact lifecycle. Existing template-version rows receive a backfilled public ID through the follow-up migration.
- [x] Confirm the minimum package set and obtain approval before adding PhpSpreadsheet/Laravel Excel and the S3 filesystem adapter.
    - **Verified:** Approved and installed direct `phpoffice/phpspreadsheet` 5.9 and `league/flysystem-aws-s3-v3` 3.35 dependencies. The local PHP runtime provides the required ZIP, XML, GD, and mbstring extensions, while the existing private filesystem disk retains local development/test storage and accepts S3-compatible production configuration without committing credentials.
- [x] Implement private upload, checksum/deduplication, scan status, and immutable original workbook storage.
    - **Verified:** `StoreTemplateWorkbook` authorizes the normalized scheduling permission and `custom_excel_templates` capability before validating a bounded `.xlsx` upload. It writes to a generated private path, records a SHA-256 checksum and pending scan status, deduplicates safely under an organization lock plus a tenant/disk/checksum unique index, keeps source metadata immutable, and writes a transaction-scoped audit event. Focused coverage passes 3 tests and 14 assertions; the full suite passes 354 tests and 2,335 assertions with PHPStan clean.
- [x] Build workbook inspection for worksheet selection, used-range metadata, merged cells, dimensions, and safe preview generation.
    - **Verified:** `InspectTemplateWorkbook` authorizes a clean tenant-owned private asset, streams it through PhpSpreadsheet without formula calculation, and returns bounded worksheet, dimension, merge, and preview metadata. Focused coverage exercises merged-cell inspection and formula-safe previews.
- [x] Define typed mapping DTOs and validators for timetable area, day columns, time rows, schedule-cell rendering, placeholders, and signatory fields.
    - **Verified:** `TemplateWorkbookMapping` converts validated JSON into typed ranges and normalized schedule fields. Its validator rejects out-of-range or colliding cells, duplicate weekday columns, incompatible time rows, and unsupported placeholders.
- [x] Implement placeholder catalogs and context-aware placeholder resolution.
    - **Verified:** `TemplatePlaceholder` exposes nine explicit keys. `ResolveTemplatePlaceholders` rehydrates the tenant-owned timetable snapshot and resolves organization, academic, timetable, version, publication, and generation values in the organization timezone.
- [x] Implement queued preview and export jobs that clone the source workbook, preserve formatting where practical, place schedule content deterministically, and write immutable output assets.
    - **Verified:** `QueueTemplateExport` authorizes and hashes tenant-bound preview/export inputs, dispatches only after commit, and reuses a permitted equivalent completed artifact. `GenerateTemplateExport` is encrypted, unique, tenant-scoped, retry-bounded, and records failures. Rendering clones the private `.xlsx`, preserves unmapped workbook content and charts, and writes a checksum-addressed private immutable file asset.
- [x] Define explicit behavior for sessions crossing multiple template slots, collisions, merged cells, unsupported formulas, charts, macros, and external links.
    - **Verified:** Sessions render into each mapped time row they occupy; same-cell collisions join in stable schedule order. Mapped formulas and non-anchor merged cells fail the run, while unmapped formulas and merged ranges remain unchanged. Charts are read/written explicitly; macros and external links are rejected before workbook loading.
- [x] Build the upload, worksheet/range mapping, placeholder mapping, preview, validation, versioning, and reuse UI.
    - **Verified:** Authorized scheduling managers have a responsive Inertia mapper that uploads a private workbook, inspects worksheets, previews bounded cells, captures typed mapping fields, creates immutable template versions, activates a version, queues previews/exports for a selected timetable version, polls run status, and downloads only signed completed artifacts. It uses generated Wayfinder actions, persists incomplete mapper input in Inertia history, and exposes backend validation and scan failures without hard-coded URLs. Feature coverage passes 4 tests and 39 assertions; TypeScript, ESLint, Prettier, and the production build pass.
- [x] Add golden-workbook regression tests for styles, merged cells, dimensions, logos, print settings, placeholders, and historical signatories.
    - **Verified:** `TemplateWorkbookGoldenTest` renders a real private `.xlsx` through the export pipeline and asserts merged formatting, column/row dimensions, the source logo, landscape print settings, placeholders, and an approved signatory name, position, and signature image. The test also proves a later signatory-profile replacement preserves the signature asset referenced by the immutable approval action. Focused template, approval, and signatory coverage passes 23 tests and 171 assertions; PHPStan passes with zero errors.

**Exit criteria:** an organization can safely map a controlled `.xlsx` layout and reproduce a published timetable without attempting arbitrary spreadsheet inference.

## Phase 11 — Administration UX and operational workflows

- [x] Provide existing Inertia/Vue authentication, settings, organization switching, membership, and invitation interfaces.
    - **Verified refinement (2026-09-09):** Members & settings now uses the organization workspace shell, with focused Members, Invitations, Roles, and Details sections. Searchable directories, role filtering, responsive member rows, permission disclosures, and explicit empty/entitlement states reduce navigation and scanning effort. Section switches and member role updates preserve filters and unsaved details; invitation forms reset after success. Member removal, invitation cancellation, and custom-role deletion use accessible confirmations with scoped validation feedback. Six browser journeys (112 assertions) and 62 organization feature tests (259 assertions) pass; desktop/mobile and dark layouts were inspected, and TypeScript, ESLint, Pint, Prettier, and the production build pass.
- [ ] Add a permission-aware application navigation organized around Setup, Resources, Scheduling, Approvals, Templates, Reports, Billing, and Audit.
    - **Progress:** Current organization members receive only implemented, authorized route groups: Workspace, Setup, Resources, Scheduling when a concrete timetable context exists, Approvals, Templates & exports for authorized scheduling managers, Billing, and the Audit activity ledger for organization administrators. Reports navigation remains pending its underlying authorized page contract.
    - **Verified refinement (2026-09-07):** Template-page creation capability uses a separate prop from shared navigation access, so the Templates & exports link remains visible when the organization can read templates but cannot create versions. Feature and browser regression coverage passes.
- [x] Implement the academic, faculty, room, subject, offering, and availability pages described in earlier phases.
    - **Verified:** `academic/Setup` provides academic-year, calendar, hierarchy, and student-group administration, while `resources/Setup` provides faculty, rooms, subjects, offerings, and availability workflows through typed Wayfinder form contracts. Frontend type checking, linting, production build, and earlier focused feature coverage pass.
- [x] Implement scheduling, version comparison, approvals, template mapping, export, billing, and audit pages described in earlier phases.
    - **Verified:** The audit ledger provides a permission-gated, tenant-scoped, action-filtered cursor view with opaque pagination cursors. It exposes actor names, action labels, target types, timestamps, and changed field names only; raw snapshots, request metadata, and internal identifiers remain server-side. Focused controller coverage passes 3 tests and 64 assertions; PHPStan, TypeScript, ESLint, Prettier, and the production build pass.
    - **Verified refinement (2026-09-07):** Approval inbox now pairs a searchable request queue with the selected review, synchronizes selection across filters, isolates decision comments, and links to the exact timetable version. Templates & exports separates the searchable library, draft-preserving workbook mapper, and export history. Desktop/mobile browser journeys verify navigation, filtering, mapper drafts, preview queuing, and approval decisions; screenshots cover both responsive layouts and the template library's dark theme. Focused feature tests, TypeScript, ESLint, Prettier, Pint, and the production build pass.
    - **Verified refinement (2026-09-08):** Approval inbox, workflow designer, and signatory profiles share permission-aware navigation. Searchable saved workflows and profiles appear before creation forms, with preserved drafts, readable review steps, and field-linked validation. Stable step identities and uncontrolled minimum-approval defaults preserve edits when steps change; normalized date-only signatory props prevent blank pages and empty date controls. Browser journeys verify workflow creation, step removal, activation, profile creation/editing, validation recovery, and viewer navigation. Desktop/mobile screenshots and dark-theme profile layouts were inspected; focused feature tests, PHPStan, TypeScript, ESLint, Prettier, Pint, and the production build pass.
- [ ] Standardize loading, empty, validation, conflict, authorization, entitlement, and failure states.
    - **Progress:** Shared foundations include a responsive `WorkspacePageHeader`, `WorkspaceState` for empty/loading/failure/access states, typed field-linked `ValidationSummary` corrections, and `SchedulingConflictList` grouping stable issue codes by operational category. The dashboard's unaffiliated-user state, academic setup's no-year state, and organizations page's no-workspace state now use the shared empty state; adoption across the remaining future domain pages remains.
    - **Progress (2026-09-08):** Approval workflow and signatory forms now use shared validation summaries, saved directories provide distinct empty and no-match states, and profile rows expose missing signatures. Standardization across the remaining administration pages is still pending.
    - **Progress (2026-09-09):** Organization details, invitations, role editors, and destructive confirmations now surface shared validation summaries. Member role updates show progress and preserve page state; member, invitation, and role directories distinguish empty and filtered results. Standardization across the remaining administration pages is still pending.
    - **Progress (2026-09-10):** Student groups now use searchable table/card records with academic-year and school-unit filters, focused creation and management dialogs, prerequisite links, and explicit closed-year/viewer states. Date, unit, and participation forms preserve independent drafts, display scoped validation and save feedback, prevent repeated submissions while pending, and restore keyboard focus on close. Ten browser journeys (164 assertions) verify creation, duplicate-code recovery, invalid dates, enrollment recovery, filtering, per-group drafts, desktop/mobile/dark layouts, and existing calendar behavior; five feature cases (20 assertions) verify optional date boundaries. Screenshots, TypeScript, ESLint, Pint, Prettier, and the production build pass. Remaining academic and administration state standardization stays open.
- [x] Add accessible table/grid navigation and responsive alternatives for scheduling workflows.
    - **Verified:** The timetable workspace implements a labelled grid with row/column semantics, keyboard commands, responsive agenda controls, and an explicit accessible agenda alternative. Frontend type checking, linting, and the production build pass.
- [x] Regenerate Wayfinder artifacts whenever backend routes change and keep generated imports out of hand-written URL strings.
    - **Verified:** Regenerated action and named-route artifacts, including the organization subscription route, with form variants; generated route imports remain the typed frontend URL boundary and `npm run types:check` passes.
- [x] Add browser smoke tests for the critical owner, scheduler, approver, teacher/viewer, and multi-organization journeys.
    - **Verified:** Pest Browser with Playwright Chromium covers owner billing navigation, an administrator's empty timetable workspace, an approval inbox, a member's viewer dashboard, and sidebar organization switching. Each journey asserts that no console or JavaScript errors occur; the isolated suite passes 5 tests and 25 assertions, and the primary CI workflow installs Chromium before its full test suite.

**Exit criteria:** every MVP backend workflow has an authorized, accessible administration interface and browser coverage for its critical path.

## Phase 12 — Test, performance, and release hardening

- [ ] Maintain a green unit, feature, architecture, browser, lint, type, format, and build pipeline.
- [ ] Run domain tests on PostgreSQL in CI, including exclusion constraints, RLS, partial indexes, hierarchy concurrency, publication races, and advisory locks.
- [x] Add contract tests for structured scheduling conflict responses and future solver boundaries.
    - **Verified:** Store-conflict and validation responses assert the complete stable issue schema and correlation metadata in focused scheduling coverage. `SchedulingGenerationContractsTest` now proves the solver port accepts only immutable public-ID snapshots, reports indeterminate and terminal progress, returns the canonical `ConstraintIssue` explanation shape, and honors cancellation without persisting schedule rows.
- [ ] Add property-based or dataset coverage for time overlap, granularity, daylight-saving/timezone conversion, overnight rejection, and availability precedence.
    - **Progress:** Named scheduling datasets now reject same-minute and overnight windows at the HTTP boundary and report off-grid local-time windows as hard granularity issues; existing scheduling and availability-resolver coverage exercises half-open overlap semantics and period-specific availability precedence. The current model stores local weekday/minute windows and has no date-time conversion behavior to test across daylight-saving transitions; that coverage remains pending a future cross-timezone contract.
- [ ] Add query-count tests for timetable views, permission resolution, hierarchy traversal, workload reports, and organization dashboards.
    - **Progress:** The scoped organization-permission resolver has a regression proving an identical second lookup reuses the request-lifecycle cache without executing SQL. Timetable read-model coverage remains at 13 queries for one and six entries, hierarchy traversal remains at two queries for a four-level closure tree, and the dashboard invitation projection remains at three queries for one and six invitations. Workload reports have no current read contract and remain to be baselined against representative PostgreSQL data.
- [ ] Add load tests for large timetable reads, validation bursts, exports, and concurrent scheduling writes.
- [ ] Review indexes using representative PostgreSQL query plans and production-like volumes.
- [ ] Add dependency, secret, static-analysis, and upload-security checks to CI.
    - **Progress:** The primary CI workflow now audits locked Composer dependencies and all npm dependencies at the high-severity threshold, rejects committed private-key material, and continues through existing PHPStan, lint, type, formatting, and full feature-test gates (including upload-validation coverage). A broader secret scanner remains pending an approved scanner/service choice.
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

- [x] Define a solver-neutral scheduling problem DTO, solution DTO, progress events, cancellation contract, and result explanation format.
    - **Verified:** `SchedulingEngine` now depends only on immutable, public-ID-based problem/solution/result DTOs, a cancellation query, and a progress reporter. Results reuse the existing stable `ConstraintIssue` explanation format, so future adapters cannot invent a second conflict contract. Contract coverage proves indeterminate progress, cancellation, deterministic seeds, and serializable public snapshots without creating or mutating schedule rows.
- [x] Add durable generation-run records with input snapshot/version, seed, status, progress, diagnostics, and output draft-version reference.
    - **Verified:** `generation_runs` retains public input snapshots, source/output version references, deterministic seeds, lifecycle/progress timestamps, diagnostics, and requesters through typed Eloquent casts and relations. Composite tenant keys reject cross-organization version references, and the follow-on PostgreSQL migration defines the existing forced `tenant_isolation` policy. Focused persistence, tenant-key, and full-suite verification pass.
- [ ] Implement a queued Laravel solver adapter for a constrained initial problem size only after manual scheduling and constraint handlers are stable.
    - **Progress:** Manual scheduling and the typed hard/soft constraint registry are complete, but the accepted architecture also requires representative production constraints before automatic scheduling begins. PostgreSQL CI verification, production-like operating data, and Phase 13 queue-worker readiness remain pending, so no queue worker or engine binding is introduced speculatively.
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
