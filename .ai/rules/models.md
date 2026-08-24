---
paths:
  - app/Models/Membership.php
  - 'app/Models/{Membership.php,MembershipRoleAssignment.php}'
  - 'app/Models/Academic*.php'
---

# Models

## Synchronize normalized roles from membership pivot saves
organization_members.role remains a compatibility field during the authorization migration. Membership pivot create/update events must provision the matching normalized unscoped role assignment so direct attach and legacy lifecycle writes cannot leave authorization stale.

## Protect explicit organization ownership
The organization owner is authoritative in organizations.owner_user_id. Policies and model events must prevent deleting that membership or using a compatibility owner role to authorize destructive organization actions; ownership changes require a dedicated transfer flow.

## Protect explicit owner role assignments
Model-level membership and normalized assignment writes must reject the Owner role for any user other than organizations.owner_user_id. Ownership changes bypass ordinary model events only inside the dedicated TransferOrganizationOwnership transaction.

## Keep academic records tenant-consistent
Academic records must keep organization_id immutable after creation. Model saves reject cross-organization parent references and invalid academic/unit date ranges; database composite foreign keys remain the final boundary. Academic units and student groups are soft-deletable, while required academic parents remain database-restricted.
