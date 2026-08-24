---
paths:
  - 'app/Scheduling/RollbackTimetableVersion.php,app/Policies/TimetableVersionPolicy.php'
---

# Scheduling Policies

## Rollback creates a new immutable-history draft
Rollback is authorized through the TimetableVersion policy and accepts only published or superseded source versions. It delegates to the transactional clone path, preserving the source status and rows while creating a new draft with based_on_version_id.
