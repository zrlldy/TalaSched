<?php

namespace App\Approvals;

use App\Enums\CapabilityKey;
use App\Enums\OrganizationPermission;
use App\Models\Organization;
use App\Models\User;
use App\Subscriptions\CapabilityGuard;
use Illuminate\Auth\Access\AuthorizationException;

class ApprovalWorkflowAuthorizer
{
    public function __construct(private CapabilityGuard $capabilities) {}

    public function allows(Organization $organization, User $actor): bool
    {
        return $actor->belongsToOrganization($organization)
            && $actor->hasOrganizationPermission($organization, OrganizationPermission::ManageScheduling)
            && $this->capabilities->allows($organization, CapabilityKey::ApprovalWorkflows);
    }

    public function authorize(Organization $organization, User $actor): void
    {
        if (! $this->allows($organization, $actor)) {
            throw new AuthorizationException('The actor is not authorized to manage approval workflows.');
        }
    }
}
