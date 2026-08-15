# ADR 0003: Keep identity control-plane tables outside tenant RLS

- Status: Accepted
- Date: 2026-08-15

## Context

Most organization-owned data is accessed only after one organization has been selected, so PostgreSQL row-level security can fail closed against `app.current_organization_id`. Two identity tables are different:

- `organization_members` is queried across organizations to authenticate membership, list available organizations, and switch the active organization.
- `organization_invitations` is queried by recipient email before an unaffiliated user has any organization context.

Applying the single-organization RLS policy to these tables would prevent those identity journeys. Setting an organization context before membership authorization would also make a user-controlled route slug the initial database authority.

## Decision

Treat `organization_members` and `organization_invitations` as identity control-plane tables rather than domain tenant tables.

- They retain explicit `organization_id` ownership and tenant-safe keys.
- They are intentionally excluded from the `app.current_organization_id` RLS policy.
- Every application query must qualify access by the authenticated user, normalized recipient identity, or an already-authorized organization.
- Domain tenant tables remain protected by enabled and forced RLS with both `USING` and `WITH CHECK` expressions.
- The runtime application role must be non-superuser, must not own protected tables, and must not have `BYPASSRLS`.
- A no-login, non-bypass owner role owns protected tables. A separate migration role may use `BYPASSRLS` for controlled schema changes and data backfills, then reassigns created objects to the owner role. The runtime application role cannot assume either role.
- Forced-RLS integration tests assume the owner role and prove that ownership alone cannot bypass tenant policies.

## Alternatives considered

### Apply organization-only RLS to memberships and invitations

Rejected because organization discovery, switching, and invitation acceptance require cross-organization identity queries before one tenant is selected.

### Set organization context from the route before membership authorization

Rejected because it treats a user-controlled slug as the first tenant authority and still does not solve cross-organization organization lists or invitation discovery.

### Add actor-aware RLS session settings

Deferred. A second policy dimension for user identity would increase connection-state and cleanup risk without protecting domain tables more strongly. It can be reconsidered if direct database access to the identity control plane expands.

## Consequences

- Identity control-plane queries require focused cross-tenant application tests and must never be used as unqualified domain queries.
- New tables with `organization_id` are RLS-protected by default. Adding another exception requires an explicit architecture decision and PostgreSQL integration-test update.
- PostgreSQL integration tests can enumerate tenant tables and fail when a domain table lacks a forced policy.
