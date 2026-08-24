---
paths:
  - 'app/Scheduling/CompareTimetableVersions.php,app/Scheduling/TimetableVersionComparisonData.php,app/Http/Requests/Scheduling/CompareTimetableVersionsRequest.php,app/Http/Controllers/Scheduling/TimetableVersionComparisonController.php'
---

# Controllers Scheduling

## Compare versions by logical entry lineage
The read-only timetable version comparison endpoint accepts organization-scoped public version IDs from one routed timetable, keys entries by stable logical_id, and returns before/after public-ID snapshots with change_types for added, removed, moved, reassigned, and content-changed entries.
