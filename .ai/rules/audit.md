---
paths:
  - 'app/Audit/**'
---

# Audit

## Scoped organization-less audit events
Organization-less audit events are inserted only through AuditLogger. On PostgreSQL, AuditLogger establishes the transaction-local app.allow_global_audit_event_insert marker required by the dedicated INSERT policy; do not write global audit_events directly. Tenant sessions must not read global audit rows.
