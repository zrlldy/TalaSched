---
paths:
  - app/Scheduling/CloneTimetableVersion.php
---

# App Scheduling

## Clone scheduling snapshot data only
Version cloning preserves entry logical lineage and copies recurring resources, reservations, dated schedule exceptions, and exception-resource pivots into the new draft with fresh row public IDs. Approval instances and export runs remain historical records tied to their source version and are not copied into a new draft.
