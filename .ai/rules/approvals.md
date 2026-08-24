---
paths:
  - 'app/Approvals/**'
---

# Approvals

## Activate workflow versions before submission
Approval workflow creation writes a new unactivated version; activation validates contiguous steps and permission/organization-role selectors, and retirement disables the workflow without mutating activated history. Submission must accept only activated versions from non-retired workflows. Named-membership and academic-unit selectors remain deferred until their schema is available.
