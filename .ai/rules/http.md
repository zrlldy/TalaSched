---
paths:
  - 'app/Http/**'
---

# Http

## Use the shared JSON command contract
Scheduling JSON commands must use ApiResponse data/error envelopes and expose the correlation ID in both meta and X-Correlation-ID. Retryable mutation routes use the idempotent middleware; do not implement ad-hoc response shapes or store raw idempotency keys.

## Use public organization HTTP identifiers
Organization navigation uses the organization slug, while organization IDs in Inertia props are public UUIDs. Member mutation routes bind Membership by public_id and must verify it belongs to the route organization. Never expose current_organization_id or use user/database IDs as membership command identifiers.

## Keep template navigation access separate from creation capability
HandleInertiaRequests shares canManageTemplates for authorized template-page navigation. Controllers must not override it with a capability-gated creation check: the library remains readable when a plan cannot create versions. Templates/Index uses the separate canCreateTemplateVersions prop for creation and export controls.

## Normalize date-only page props at the read boundary
Inertia props used by date inputs must be Y-m-d strings or null. Query-builder projections can return timestamp strings despite an Eloquent date cast on the source model. Normalize signatory validity dates in the controller so date inputs populate and client-side labels render consistently across database drivers.

## Cast validated numeric form strings before strict domain services
Laravel integer validation accepts HTML form numeric strings without casting them. Normalize validated numeric fields at the controller boundary before calling strict domain services, while preserving null for optional inputs. Faculty creation explicitly casts supplied daily/weekly minute limits; only compare optional limits when both are supplied.
