# Architecture Review

Reviewed on 2026-08-14 against the current repository, [system architecture](ARCHITECTURE.md), and [implementation plan](../plans/implementation-plan.md).

## Outcome

The existing architecture is directionally complete and consistent with the product requirements. Its central decisions should be preserved:

- Laravel/Inertia/Vue modular monolith;
- organization-owned subscriptions and shared-schema multitenancy;
- configurable academic-unit hierarchy;
- one canonical scheduling model with resource reservations;
- immutable timetable-version snapshots;
- typed constraint handlers with stored configuration;
- versioned sequential approvals and signatory snapshots;
- controlled Excel mapping rather than arbitrary workbook inference;
- provider-neutral capabilities and a future solver port.

No accepted architecture decision needs to be reversed. The gaps are primarily executable contracts, production verification, quantified operating assumptions, and consistent implementation of decisions that are currently schema-only.

## What already exists

| Area | Architecture | Repository evidence | Assessment |
|---|---|---|---|
| Identity | Fortify authentication and multi-organization users | Login, registration, verification, password reset, 2FA, passkeys, organization switching and invitations | Implemented foundation |
| Organization tenancy | Shared schema, tenant context, RLS, policies | `organization_id` columns, request tenant context, conditional RLS migration | Partial; PostgreSQL and non-HTTP paths are unverified |
| Authorization | Normalized roles and scoped assignments | Tables exist; legacy membership role enum remains active | Schema-only transition |
| Academic model | Configurable unit types, hierarchy and calendar | Broad schema and initial models exist | Foundation only |
| Resources/catalog | Unified resource identity, faculty, rooms, offerings | Broad schema and initial models exist | Foundation only |
| Scheduling | Canonical entries and reservations | Create/validate endpoints, structured issues, tests, exclusion constraint migration | Useful vertical slice, incomplete constraint coverage |
| Versioning | Immutable snapshots and publication | Clone/publish actions and focused tests | Partial workflow |
| Approvals | Versioned sequential workflow | Schema and submission snapshot action | Partial backend |
| Subscriptions | Typed plan capabilities | Schema and entitlement service | Partial; tests currently fail |
| Templates/audit | Async artifacts and append-only events | Schema and logger abstraction | Schema/service foundation only |
| Frontend | Inertia/Vue admin shell | Authentication, settings, organizations, component library | Starter foundation; domain UI absent |

## Consistency findings

### Aligned

- The data model uses one canonical `schedule_entries` source and projects bookings into `schedule_reservations`.
- Recurring time uses weekday plus local wall-clock minute integers, matching the documented MVP recurrence rule.
- Offering components snapshot subject requirements, allowing historical schedules to survive catalog edits.
- Published history is modeled as full versions, and rollback is intended to clone rather than mutate history.
- Approval definitions and approval instances are separated, preserving workflow history.
- Excel mappings are versioned JSON configuration while executable workbook behavior remains outside the database.

### Partially aligned

- The architecture names `organization_memberships`; the existing database uses `organization_members`. This is a naming difference, not a domain-model difference. Renaming a live table is not required for correctness and should only be done with a migration benefit analysis.
- The architecture illustrates `app/Modules/*`; the current code uses domain-oriented top-level directories such as `app/Scheduling`, `app/Approvals`, and `app/Subscriptions`. Preserve working code and converge incrementally rather than performing a broad mechanical move.
- Public UUID columns exist on many aggregate roots, but current scheduling requests still accept internal integer IDs.
- PostgreSQL exclusion and RLS definitions exist, but the active development database is SQLite and no PostgreSQL integration suite proves them.
- `AuditLogger` exists but critical actions do not consistently call it.
- Architecture entities such as saved timetable views, named approval-step members, subscription events, separate template mappings, and export artifacts are represented differently or not yet present. Each should be justified when its feature is implemented rather than added speculatively.

## Missing architectural detail now supplied by companion documents

- [Backend design](../backend/BACKEND_DESIGN.md): request contracts, transaction boundaries, error semantics, tenancy, idempotency, data invariants, async processing, observability, and test strategy.
- [Frontend architecture](../frontend/FRONTEND_ARCHITECTURE.md): information architecture, interaction model, visual direction, page contracts, accessibility, responsiveness, and async/conflict states.
- [Cross-layer review](CROSS_LAYER_REVIEW.md): use-case mapping and contract consistency between database, backend, and UI.
- [ADR 0001](../decisions/0001-modular-monolith.md): formal record of the already-selected modular-monolith decision.

## Provisional operating assumptions

These are planning targets, not accepted service-level commitments. Validate them with representative institutions before production sizing.

| Dimension | Provisional planning value |
|---|---|
| Organizations | 1,000 active organizations in the initial shared deployment |
| Organization size | Up to 10 campuses, 10,000 schedulable resources, and 100,000 schedule entries across retained versions |
| Interactive concurrency | 50 concurrent authenticated users per large organization; 10 concurrent schedule writers |
| Common read latency | p95 server response under 500 ms for paginated admin lists and filtered timetable views |
| Validation latency | p95 under 750 ms for one manual-entry validation at representative scale |
| Mutation latency | p95 under 1 second excluding queued work |
| Async acknowledgement | Upload/export/generation request accepted within 2 seconds |
| Availability | 99.9% monthly after production launch, excluding announced maintenance |
| Recovery | Initial RPO 15 minutes and RTO 4 hours, pending hosting and budget confirmation |

## Critical failure analysis

| Failure | Detection | Mitigation | Recovery |
|---|---|---|---|
| Missing or leaked tenant context | Cross-tenant tests, RLS-denied metrics, correlation logs | Fail-closed RLS, request/job middleware, tenant-safe references | Disable affected workers, rotate sessions if needed, audit access |
| Concurrent double booking | Exclusion-violation metric and scheduling conflict logs | Sorted advisory locks plus PostgreSQL exclusion constraint | Return structured conflict and let the scheduler retry |
| Partial publication | Version-state invariant alert and audit reconciliation | Single transaction, locked timetable, post-commit side effects | Roll forward state from audit/outbox; never edit published rows |
| Approval replay/race | Duplicate-decision metric and unique/idempotency guards | Lock active step and use idempotency keys | Replay safe command or manually reconcile append-only actions |
| Workbook parser abuse | Scan failures, ZIP expansion limits, parser timeouts | Quarantine, reject macros/external links, isolated queued processing | Delete quarantined artifact and surface actionable failure |
| Queue backlog | Queue depth/age alerts | Separate queues, rate limits, worker autoscaling, idempotent jobs | Add workers, retry safe jobs, cancel obsolete runs |
| Subscription drift | Entitlement-denial and usage reconciliation metrics | Central entitlement/usage service and transactional reservations | Recompute counters and apply audited overrides |

## Open decisions

These decisions are intentionally unresolved and must not be inferred during implementation:

1. Production hosting topology and whether Laravel Cloud or another managed platform will operate PostgreSQL, Redis, workers, and object storage.
2. Whether named approval-step members require a dedicated relation in MVP or role/permission selectors are sufficient.
3. Whether to retain `organization_members` permanently or rename it during the normalized RBAC transition.
4. Exact retention periods for published versions, audit events, uploaded templates, generated exports, and deleted-organization data.
5. Which fonts may be self-hosted for the product visual system; the frontend design provides dependency-free fallbacks.
6. Billing provider and tax/invoicing scope. Manual subscription administration remains the architectural default until selected.

The registration policy was resolved in [ADR 0002](../decisions/0002-public-registration-without-personal-organizations.md): public registration remains available, but it never creates a personal organization.

## Review conclusion

The next implementation work should not expand domain breadth. First restore the green baseline, then make the tenant and authorization foundation production-safe. Those dependencies protect every later module and are the next dependency-safe tasks in the implementation plan.
