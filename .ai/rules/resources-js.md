---
paths:
  - 'resources/js/**'
---

# Resources Js

## Preserve scheduling JSON conflict failures
Scheduling endpoints return ApiResponse error.issues envelopes, not Laravel errors bags. Inertia useHttp consumes 422 responses and resolves undefined, so do not infer command success from await completing. Reuse lib/scheduling.ts for schedule-entry writes: it uses Inertia's configured XHR client, checks HTTP status, preserves structured conflicts, and keeps create idempotency keys stable for identical retries. HttpResponse.data is raw JSON text.

## Preserve page state for member mutations through router.visit
When calling router.visit with a Wayfinder mutation route, set preserveState: true if filters, selected sections, or unsaved drafts must survive the response. The route's HTTP verb does not give router.visit the state-preserving defaults of router.post/patch. Member role changes have browser coverage for this behavior.

## Reset remembered creation forms to explicit blank defaults
Inertia v3 useForm initializes defaults from remembered data when a keyed component remounts. After successful creation, set explicit empty/default values with form.defaults(...) before form.reset(); reset() alone can restore the old draft. Preserve drafts on close or validation failure. Academic calendar browser coverage switches periods before saving to exercise this path.
