---
paths:
  - '{app,database/factories,resources/js}/**'
---

# Js

## Accounts do not imply organizations
Public registration creates only a user account. Never auto-create personal organizations. Tenant-dependent factories must opt into withOwnedOrganization(), and unaffiliated users must remain supported so they can explicitly create an organization or accept an invitation. See ADR 0002.
