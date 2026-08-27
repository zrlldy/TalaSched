<?php

namespace App\Policies;

use App\Enums\OrganizationPermission;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

abstract class OrganizationOwnedPolicy
{
    /**
     * Get the permission required to mutate the resource.
     */
    abstract protected function managementPermission(): OrganizationPermission;

    /**
     * Determine whether the user can view resources in the organization.
     */
    public function viewAny(User $user, Organization $organization): bool
    {
        return $user->belongsToOrganization($organization);
    }

    /**
     * Determine whether the user can view the resource.
     */
    public function view(User $user, Model $model): bool
    {
        $organization = $this->organizationFor($model);

        return $organization instanceof Organization
            && $user->belongsToOrganization($organization);
    }

    /**
     * Determine whether the user can create a resource in the organization.
     */
    public function create(User $user, Organization $organization): bool
    {
        return $user->hasOrganizationPermission($organization, $this->managementPermission());
    }

    /**
     * Determine whether the user can update the resource.
     */
    public function update(User $user, Model $model): bool
    {
        return $this->canManage($user, $model);
    }

    /**
     * Determine whether the user can delete the resource.
     */
    public function delete(User $user, Model $model): bool
    {
        return $this->canManage($user, $model);
    }

    /**
     * Determine whether the user may mutate a tenant-owned model.
     */
    protected function canManage(User $user, Model $model): bool
    {
        $organization = $this->organizationFor($model);

        return $organization instanceof Organization
            && $user->hasOrganizationPermission($organization, $this->managementPermission());
    }

    /**
     * Resolve the organization owning a tenant model without trusting a caller-supplied relation.
     */
    protected function organizationFor(Model $model): ?Organization
    {
        if ($model->relationLoaded('organization') && $model->getRelation('organization') instanceof Organization) {
            return $model->getRelation('organization');
        }

        $organizationId = $model->getAttribute('organization_id');

        return $organizationId === null
            ? null
            : Organization::query()->whereKey($organizationId)->first();
    }
}
