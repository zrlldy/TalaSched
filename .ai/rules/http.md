---
paths:
  - 'app/Http/**'
---

# Http

## Use the shared JSON command contract
Scheduling JSON commands must use ApiResponse data/error envelopes and expose the correlation ID in both meta and X-Correlation-ID. Retryable mutation routes use the idempotent middleware; do not implement ad-hoc response shapes or store raw idempotency keys.

## Use public organization HTTP identifiers
Organization navigation uses the organization slug, while organization IDs in Inertia props are public UUIDs. Member mutation routes bind Membership by public_id and must verify it belongs to the route organization. Never expose current_organization_id or use user/database IDs as membership command identifiers.
