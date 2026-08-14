---
paths:
  - '{app,database}/**'
---

# Appdatabase

## Preserve explicit organization ownership
Every organization must have a non-null public_id and owner_user_id. Creation paths and factories must create a matching owner-role membership in the same transaction/lifecycle; organization soft deletion preserves memberships for historical ownership integrity. Do not delete an owner account while any active or soft-deleted organization still references it.
