---
paths:
  - app/Concerns/HasOrganizations.php
---

# Concerns

## Resolve scoped permissions through closure descendants
An academic-unit role assignment applies to its assigned ancestor unit and descendants. Scope reads must filter both assignment and closure rows by organization_id and reject a requested AcademicUnit from another organization.
