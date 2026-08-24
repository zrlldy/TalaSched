<?php

namespace App\Concerns;

use App\Authorization\OrganizationPermissionResolver;
use App\Data\OrganizationPermissions;
use App\Data\UserOrganization;
use App\Enums\CapabilityKey;
use App\Enums\OrganizationPermission;
use App\Enums\OrganizationRole;
use App\Models\AcademicUnit;
use App\Models\Membership;
use App\Models\MembershipRoleAssignment;
use App\Models\Organization;
use App\Subscriptions\CapabilityGuard;
use App\Tenancy\TenantContext;
use Closure;
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
        return $organization->owner_user_id === $this->id;
    }

    /**
     * Get the user's role on the given organization.
     */
    public function organizationRole(Organization $organization): ?OrganizationRole
    {
        $membership = $this->organizationMemberships()
            ->where('organization_id', $organization->id)
            ->first();

        if (! $membership) {
            return null;
        }

        return $this->runWithOrganizationContext($organization, function () use ($membership): ?OrganizationRole {
            return $membership->roleAssignments()
                ->whereNull('academic_unit_id')
                ->with('role')
                ->get()
                ->map(fn (MembershipRoleAssignment $assignment): ?OrganizationRole => OrganizationRole::tryFrom($assignment->role->code))
                ->filter()
                ->sortByDesc(fn (OrganizationRole $role): int => $role->level())
                ->first();
        });
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
        $permissions = $this->organizationPermissionCodes($organization);

        return new OrganizationPermissions(
            canUpdateOrganization: $permissions->contains(OrganizationPermission::UpdateOrganization->value),
            canDeleteOrganization: $permissions->contains(OrganizationPermission::DeleteOrganization->value),
            canAddMember: $permissions->contains(OrganizationPermission::AddMember->value),
            canUpdateMember: $permissions->contains(OrganizationPermission::UpdateMember->value),
            canRemoveMember: $permissions->contains(OrganizationPermission::RemoveMember->value),
            canCreateInvitation: $permissions->contains(OrganizationPermission::CreateInvitation->value),
            canCancelInvitation: $permissions->contains(OrganizationPermission::CancelInvitation->value),
            canManageCustomRoles: $permissions->contains(OrganizationPermission::UpdateMember->value)
                && app(CapabilityGuard::class)->allows($organization, CapabilityKey::CustomRoles),
            customRolesEnabled: app(CapabilityGuard::class)->allows($organization, CapabilityKey::CustomRoles),
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
    public function hasOrganizationPermission(
        Organization $organization,
        OrganizationPermission $permission,
        ?AcademicUnit $academicUnit = null,
    ): bool {
        return app(OrganizationPermissionResolver::class)->hasPermission($this, $organization, $permission, $academicUnit);
    }

    /**
     * Get all permissions granted by the user's normalized unscoped roles.
     *
     * @return Collection<int, string>
     */
    protected function organizationPermissionCodes(Organization $organization, ?AcademicUnit $academicUnit = null): Collection
    {
        return app(OrganizationPermissionResolver::class)->permissionsFor($this, $organization, $academicUnit);
    }

    /**
     * Execute a normalized authorization read under the target tenant.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    protected function runWithOrganizationContext(Organization $organization, Closure $callback): mixed
    {
        $tenantContext = app(TenantContext::class);

        if ($tenantContext->organization()?->is($organization)) {
            return $callback();
        }

        return $tenantContext->run($organization, fn (): mixed => $callback());
    }
}
