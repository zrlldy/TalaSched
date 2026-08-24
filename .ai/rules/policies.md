---
paths:
  - 'app/Policies/**'
  - app/Policies/RolePolicy.php
---

# Policies

## Authorize tenant models through normalized permissions
Policies for tenant-owned models must resolve organization ownership from the model's organization_id, allow reads only for organization members, and require the mapped OrganizationPermission for mutations. Keep owner-protection rules in policies; do not create speculative policies for schema-only domains without models or commands.

## Gate custom roles by entitlement
Role reads are available to organization members, but custom-role mutations require both normalized UpdateMember permission and EntitlementService's custom_roles capability. System roles are immutable through the policy.
