---
paths:
  - 'resources/js/**'
---

# Resources Js

## Preserve scheduling JSON conflict failures
Scheduling endpoints return ApiResponse error.issues envelopes, not Laravel errors bags. Inertia useHttp consumes 422 responses and resolves undefined, so do not infer command success from await completing. Reuse lib/scheduling.ts for schedule-entry writes: it uses Inertia's configured XHR client, checks HTTP status, preserves structured conflicts, and keeps create idempotency keys stable for identical retries. HttpResponse.data is raw JSON text.
