---
paths:
  - '{app,database}/**'
---

# Appdatabase

## Preserve explicit organization ownership
Every organization must have a non-null public_id and owner_user_id. Creation paths and factories must create a matching owner-role membership in the same transaction/lifecycle; organization soft deletion preserves memberships for historical ownership integrity. Do not delete an owner account while any active or soft-deleted organization still references it.

## Keep tenant references composite
Every tenant-owned row, including pivot tables, carries organization_id. References between tenant-owned tables must enforce matching organization_id with a composite foreign key; single-column foreign keys alone are insufficient.

## Keep identity control plane outside tenant RLS
organization_members and organization_invitations are identity control-plane tables under ADR 0003. Keep explicit organization ownership and actor/recipient/organization-qualified queries, but do not apply the single-organization RLS policy because discovery and invitation acceptance happen before tenant selection. All other organization-owned tables are RLS-protected by default.
