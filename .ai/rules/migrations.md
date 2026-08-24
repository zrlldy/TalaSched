---
paths:
  - 'app/Subscriptions/**,app/Enums/SubscriptionStatus.php,database/migrations/*organization_subscriptions*'
---

# Migrations

## Centralize subscription access windows
EntitlementService resolves the latest organization subscription through SubscriptionStatus. Trialing uses trial_ends_at then period_ends_at; active and canceled use period_ends_at; grace_period and past_due use grace_ends_at; expired and unknown statuses deny. Keep capability checks centralized and do not compare plan names.
