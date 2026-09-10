---
paths:
  - 'app/Catalog/**'
---

# Catalog

## Recover missing offering components without replacing snapshots
Subject requirements are copied into offering-specific snapshots when an offering is created. Offerings created before requirements exist need an explicit, audited recovery through addMissingComponentSnapshots; never overwrite existing snapshot durations, instructors, or timetable references when catalog requirements change. Recovery accepts the offering public UUID and adds only missing requirements under the organization lock. SubjectComponent has no public UUID, so do not expose its internal ID as a frontend identifier.
