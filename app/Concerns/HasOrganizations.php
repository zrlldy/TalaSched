<?php

namespace App\Concerns;

use App\Data\OrganizationPermissions;
use App\Data\UserOrganization;
use App\Enums\OrganizationPermission;
use App\Enums\OrganizationRole;
use App\Models\Membership;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;

trait HasOrganizations
{
    /**
     * Get all of the organizations the user belongs to.
     *
     * @return BelongsToMany<Organization, $this>
     */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_members', 'user_id', 'organization_id')
            ->withPivot(['role'])
            ->withTimestamps();
    }

    /**
     * Get all of the organizations the user owns.
     *
     * @return HasMany<Organization, $this>
     */
    public function ownedOrganizations(): HasMany
    {
        return $this->hasMany(Organization::class, 'owner_user_id');
    }

    /**
     * Get all of the memberships for the user.
     *
     * @return HasMany<Membership, $this>
     */
    public function organizationMemberships(): HasMany
    {
        return $this->hasMany(Membership::class, 'user_id');
    }

    /**
     * Get the user's current organization.
     *
     * @return BelongsTo<Organization, $this>
     */
    public function currentOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'current_organization_id');
    }

    /**
     * Switch to the given organization.
     */
    public function switchOrganization(Organization $organization): bool
    {
        if (! $this->belongsToOrganization($organization)) {
            return false;
        }

        $this->update(['current_organization_id' => $organization->id]);
        $this->setRelation('currentOrganization', $organization);

        URL::defaults(['current_organization' => $organization->slug]);

        return true;
    }

    /**
     * Clear the user's current organization.
     */
    public function clearCurrentOrganization(): void
    {
        $this->update(['current_organization_id' => null]);
        $this->unsetRelation('currentOrganization');
    }

    /**
     * Switch to the first available organization other than the excluded one.
     */
    public function switchToFallbackOrganization(?Organization $excluding = null): ?Organization
    {
        $fallbackOrganization = $this->fallbackOrganization($excluding);

        if ($fallbackOrganization) {
            $this->switchOrganization($fallbackOrganization);

            return $fallbackOrganization;
        }

        $this->clearCurrentOrganization();

        return null;
    }

    /**
     * Determine if the user belongs to the given organization.
     */
    public function belongsToOrganization(Organization $organization): bool
    {
        return $this->organizations()->where('organizations.id', $organization->id)->exists();
    }

    /**
     * Determine if the given organization is the user's current organization.
     */
    public function isCurrentOrganization(Organization $organization): bool
    {
        return $this->current_organization_id === $organization->id;
    }

    /**
     * Determine if the user is the owner of the given organization.
     */
    public function ownsOrganization(Organization $organization): bool
    {
        return $this->organizationRole($organization) === OrganizationRole::Owner;
    }

    /**
     * Get the user's role on the given organization.
     */
    public function organizationRole(Organization $organization): ?OrganizationRole
    {
        return $this->organizationMemberships()
            ->where('organization_id', $organization->id)
            ->first()
            ?->role;
    }

    /**
     * Get the user's organizations as a collection of UserOrganization objects.
     *
     * @return Collection<int, UserOrganization>
     */
    public function toUserOrganizations(bool $includeCurrent = false): Collection
    {
        return $this->organizations()
            ->get()
            ->map(fn (Organization $organization) => ! $includeCurrent && $this->isCurrentOrganization($organization) ? null : $this->toUserOrganization($organization))
            ->filter()
            ->values();
    }

    /**
     * Get the user's organization as a UserOrganization object.
     */
    public function toUserOrganization(Organization $organization): UserOrganization
    {
        $role = $this->organizationRole($organization);

        return new UserOrganization(
            id: $organization->public_id,
            name: $organization->name,
            slug: $organization->slug,
            role: $role?->value,
            roleLabel: $role?->label(),
            isCurrent: $this->isCurrentOrganization($organization),
        );
    }

    /**
     * Get the standard permissions for a organization as a OrganizationPermissions object.
     */
    public function toOrganizationPermissions(Organization $organization): OrganizationPermissions
    {
        $role = $this->organizationRole($organization);

        return new OrganizationPermissions(
            canUpdateOrganization: $role?->hasPermission(OrganizationPermission::UpdateOrganization) ?? false,
            canDeleteOrganization: $role?->hasPermission(OrganizationPermission::DeleteOrganization) ?? false,
            canAddMember: $role?->hasPermission(OrganizationPermission::AddMember) ?? false,
            canUpdateMember: $role?->hasPermission(OrganizationPermission::UpdateMember) ?? false,
            canRemoveMember: $role?->hasPermission(OrganizationPermission::RemoveMember) ?? false,
            canCreateInvitation: $role?->hasPermission(OrganizationPermission::CreateInvitation) ?? false,
            canCancelInvitation: $role?->hasPermission(OrganizationPermission::CancelInvitation) ?? false,
        );
    }

    public function fallbackOrganization(?Organization $excluding = null): ?Organization
    {
        return $this->organizations()
            ->when($excluding, fn ($query) => $query->where('organizations.id', '!=', $excluding->id))
            ->orderByRaw('LOWER(organizations.name)')
            ->first();
    }

    /**
     * Determine if the user has the given permission on the organization.
     */
    public function hasOrganizationPermission(Organization $organization, OrganizationPermission $permission): bool
    {
        return $this->organizationRole($organization)?->hasPermission($permission) ?? false;
    }
}
