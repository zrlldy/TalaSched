---
paths:
  - 'app/Subscriptions/**'
---

# Subscriptions

## Centralize capability decisions
Use CapabilityGuard as the provider-neutral boundary for feature checks, shared entitlement maps, and numeric capacity assertions. Policies and Inertia context props should depend on the guard rather than querying plans or EntitlementService directly; transactional usage reservations remain required before enforcing raced counts.

## Lock lifetime capacity counters
UsageService stores non-periodic member and active-timetable capacity in usage_counters using the stable 1900-01-01 through 9999-12-31 period. Missing counters initialize from existing tenant rows; reservations lock the unique counter row and validate the entitlement before changing quantity inside a retried transaction.
