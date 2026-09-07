<?php

namespace App\Policies;

use App\Enums\CapabilityKey;
use App\Enums\OrganizationPermission;
use App\Models\ExcelTemplate;
use App\Models\Organization;
use App\Models\User;
use App\Subscriptions\CapabilityGuard;
use Illuminate\Database\Eloquent\Model;

class ExcelTemplatePolicy extends OrganizationOwnedPolicy
{
    public function __construct(private CapabilityGuard $capabilities) {}

    public function viewAny(User $user, Organization $organization): bool
    {
        return $user->hasOrganizationPermission($organization, OrganizationPermission::ManageScheduling);
    }

    public function view(User $user, Model $model): bool
    {
        return $model instanceof ExcelTemplate
            && parent::view($user, $model)
            && $this->canManage($user, $model);
    }

    public function create(User $user, Organization $organization): bool
    {
        return parent::create($user, $organization)
            && $this->capabilities->allows($organization, CapabilityKey::CustomExcelTemplates);
    }

    public function update(User $user, Model $model): bool
    {
        if (! $model instanceof ExcelTemplate || ! parent::update($user, $model)) {
            return false;
        }

        $organization = $this->organizationFor($model);

        return $organization instanceof Organization
            && $this->capabilities->allows($organization, CapabilityKey::CustomExcelTemplates);
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    public function restore(User $user, Model $model): bool
    {
        return false;
    }

    public function forceDelete(User $user, Model $model): bool
    {
        return false;
    }

    protected function managementPermission(): OrganizationPermission
    {
        return OrganizationPermission::ManageScheduling;
    }
}
