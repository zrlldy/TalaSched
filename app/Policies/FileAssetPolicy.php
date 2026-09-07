<?php

namespace App\Policies;

use App\Enums\CapabilityKey;
use App\Enums\OrganizationPermission;
use App\Models\FileAsset;
use App\Models\Organization;
use App\Models\User;
use App\Subscriptions\CapabilityGuard;
use Illuminate\Database\Eloquent\Model;

class FileAssetPolicy extends OrganizationOwnedPolicy
{
    public function __construct(private CapabilityGuard $capabilities) {}

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user, Organization $organization): bool
    {
        return $user->hasOrganizationPermission($organization, OrganizationPermission::ManageScheduling);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Model $model): bool
    {
        if (! $model instanceof FileAsset || ! parent::view($user, $model)) {
            return false;
        }

        $organization = $this->organizationFor($model);

        return $organization instanceof Organization
            && $user->hasOrganizationPermission($organization, OrganizationPermission::ManageScheduling);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user, Organization $organization): bool
    {
        return $user->hasOrganizationPermission($organization, OrganizationPermission::ManageScheduling)
            && $this->capabilities->allows($organization, CapabilityKey::CustomExcelTemplates);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Model $model): bool
    {
        return false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Model $model): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Model $model): bool
    {
        return false;
    }

    protected function managementPermission(): OrganizationPermission
    {
        return OrganizationPermission::ManageScheduling;
    }
}
