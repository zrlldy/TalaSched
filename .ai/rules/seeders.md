---
paths:
  - database/seeders/SubscriptionCatalogSeeder.php
---

# Seeders

## Seed subscription catalog idempotently
Seed capability metadata and built-in plan values from CapabilityKey inside a transaction. Use upserts keyed by stable codes so rerunning the seeder is safe; product code must not compare plan names directly.
