---
paths:
  - 'app/Scheduling/ValidateTimetableVersion.php,app/Scheduling/ValidateScheduleEntry.php,app/Scheduling/PublishTimetableVersion.php'
---

# Scheduling Scheduling

## Reuse canonical hard validation for version gates
ValidateTimetableVersion reuses ValidateScheduleEntry for every persisted entry and adds public/logical entry context to hard issues. Publication invokes it inside the locked transaction with only version-editability checking disabled; all other hard handlers remain authoritative. Approval completion should call the same service before its final transition.
