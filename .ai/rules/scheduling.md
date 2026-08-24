---
paths:
  - 'app/Policies/ScheduleEntryPolicy.php,app/Scheduling/**,app/Http/Controllers/Scheduling/**'
---

# Scheduling

## Gate and audit manual scheduling mutations
ScheduleEntryPolicy requires the organization scheduling permission and an active manual_scheduling entitlement for create, update, and delete; read-only scheduling views remain available to members. Scheduling mutation actions accept the authenticated actor and write create, update, delete, and exception audit events inside the same transaction.
