---
paths:
  - 'app/Approvals/**,app/Enums/Approval*.php,app/Scheduling/ValidateTimetableVersion.php,app/Policies/TimetableVersionPolicy.php'
---

# Enums Scheduling Policies

## Lock and audit approval transitions
Approval submission snapshots selector metadata and role codes into instance steps. DecideTimetableApproval locks the instance, active step, and version; requires an organization-scoped idempotency key, blocks duplicate actor decisions and disallowed self-approval, snapshots signatories, and validates hard constraints before final approval. Keep publication as a separate command; add outbox infrastructure only when a real external publication consumer exists.
