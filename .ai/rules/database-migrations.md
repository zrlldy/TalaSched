---
paths:
  - 'database/migrations/*audit*'
---

# Database Migrations

## Preserve immutable audit history across account deletion
Audit actor and impersonator IDs are historical values, not cascading references: do not reintroduce null-on-delete foreign keys that would mutate immutable audit rows. SQLite table rebuilds caused by audit schema changes must recreate the update/delete rejection triggers.
