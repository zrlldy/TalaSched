# ADR 0004: Use PostgreSQL 17 with separated production roles

- Status: Accepted
- Date: 2026-08-15

## Context

TalaSched relies on PostgreSQL behavior that SQLite cannot prove: forced row-level security, exclusion constraints, partial indexes, advisory locks, and extension-backed GiST operators. The runtime database topology also affects tenant isolation because table ownership and `BYPASSRLS` can bypass policies when configured incorrectly.

The application can remain deployment-provider neutral, but production database behavior needs a concrete baseline before scheduling mutation work expands.

## Decision

Use PostgreSQL 17 as the supported production database baseline.

- Runtime application connections use a login role that is not a superuser, does not own RLS-protected tables, and does not have `BYPASSRLS`.
- Protected tables are owned by a no-login, non-bypass owner role.
- Migrations use a separate login role with controlled `BYPASSRLS` for schema changes and backfills, then created objects are reassigned to the owner role.
- Production sessions use the `public` schema and UTC database timezone.
- Production database connections require TLS. Local development and ephemeral CI PostgreSQL services may disable this explicitly.
- `btree_gist` is required because schedule reservation exclusion constraints depend on GiST operator support for scalar columns.
- `php artisan database:verify-production` is the operational readiness check for the database baseline.

## Alternatives considered

### Keep database version provider-defined

Rejected. The application depends on PostgreSQL-specific behavior and needs one tested major version as the release contract.

### Let the runtime application role own tables

Rejected. Table ownership weakens RLS guarantees unless every protected table is forced correctly forever. A separate owner role makes misconfiguration easier to detect.

### Use one privileged role for migrations and runtime

Rejected. It would make deployment simpler but would put bypass privileges on normal application traffic.

## Consequences

- SQLite remains acceptable for fast local tests, but it is not release evidence for PostgreSQL behavior.
- Managed PostgreSQL providers may use different role names, but the privilege properties must match this decision.
- CI must run a PostgreSQL integration lane that provisions equivalent roles and runs the readiness command as the application role.
- Production deployment remains blocked until the verifier passes against the real provisioned database.
