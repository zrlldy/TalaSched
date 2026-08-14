---
paths:
  - 'app/**'
---

# App

## Use TenantContext for every execution mode
TenantContext is the sole boundary for tenant-owned work. HTTP middleware uses run(); tenant jobs carry an organization public UUID and attach UseTenantContext; CLI and scheduled maintenance use forEachOrganization() or runByPublicId(). Never hand-roll set/clear pairs, use internal organization IDs in job payloads, or leave context established after work.
