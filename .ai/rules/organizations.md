---
paths:
  - 'app/Actions/Organizations/**'
---

# Organizations

## Include tenant keys when syncing role permissions
The tenant-safe foreign-key migration adds a required organization_id to role_permissions. Any role-permission write must run inside TenantContext and include the organization_id pivot value; a plain belongsToMany sync is invalid.

## Transfer ownership through a locked action
Ownership changes must go through TransferOrganizationOwnership. The action authorizes the explicit owner, runs inside TenantContext and a retried transaction, locks the organization and memberships, requires an existing member target, updates owner_user_id and compatibility roles together, reprovisions normalized assignments, and records the before/after audit event.

## Keep custom role mutations tenant-safe
Custom role actions run inside TenantContext transactions, synchronize only canonical permissions with organization_id pivot values, expose role codes as route keys, and refuse deletion while assignments exist.
