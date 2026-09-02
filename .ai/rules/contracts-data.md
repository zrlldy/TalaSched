---
paths:
  - 'app/Scheduling/{Contracts,Data}/**'
---

# Contracts Data

## Keep automatic scheduling solver-neutral
SchedulingEngine accepts immutable public-ID snapshots and returns proposed entries plus the existing ConstraintIssue explanation shape; it must not receive Eloquent models or persist schedule rows. Implementations report progress, honor cancellation, and leave canonical validation plus draft persistence to the future generation action.
