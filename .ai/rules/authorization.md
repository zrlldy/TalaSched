---
paths:
  - 'app/Authorization/**'
---

# Authorization

## Keep permission resolution lifecycle-scoped
Bind OrganizationPermissionResolver with Laravel's scoped lifecycle rather than a shared cache store. Invalidate organization-wide results after normalized role or permission provisioning, and call explicit user/scope invalidation after direct assignment changes.
