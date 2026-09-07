<?php

namespace App\Policies;

use App\Enums\OrganizationPermission;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\User;

class AuditEventPolicy
{
    /**
     * Determine whether the user can review the organization's audit ledger.
     */
    public function viewAny(User $user, Organization $organization): bool
    {
        return $user->hasOrganizationPermission($organization, OrganizationPermission::UpdateOrganization);
    }

    /**
     * Determine whether the user can review an organization-owned audit event.
     */
    public function view(User $user, AuditEvent $auditEvent): bool
    {
        $organization = $this->organization($auditEvent);

        return $organization instanceof Organization
            && $this->viewAny($user, $organization);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user, Organization $organization): bool
    {
        return false;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, AuditEvent $auditEvent): bool
    {
        return false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, AuditEvent $auditEvent): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, AuditEvent $auditEvent): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, AuditEvent $auditEvent): bool
    {
        return false;
    }

    private function organization(AuditEvent $auditEvent): ?Organization
    {
        if ($auditEvent->relationLoaded('organization') && $auditEvent->organization instanceof Organization) {
            return $auditEvent->organization;
        }

        return $auditEvent->organization_id === null
            ? null
            : Organization::query()->find($auditEvent->organization_id);
    }
}
