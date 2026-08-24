<?php

namespace App\Authorization;

use App\Enums\OrganizationPermission;
use App\Models\AcademicUnit;
use App\Models\AcademicUnitClosure;
use App\Models\MembershipRoleAssignment;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Support\Collection;

class OrganizationPermissionResolver
{
    /** @var array<string, array<int, string>> */
    private array $permissionCache = [];

    public function __construct(private TenantContext $tenantContext) {}

    /**
     * Determine whether the user has a permission in the requested scope.
     */
    public function hasPermission(
        User $user,
        Organization $organization,
        OrganizationPermission $permission,
        ?AcademicUnit $academicUnit = null,
    ): bool {
        return $this->permissionsFor($user, $organization, $academicUnit)->contains($permission->value);
    }

    /**
     * Resolve permissions from normalized assignments for this lifecycle.
     *
     * @return Collection<int, string>
     */
    public function permissionsFor(User $user, Organization $organization, ?AcademicUnit $academicUnit = null): Collection
    {
        $cacheKey = $this->cacheKey($user, $organization, $academicUnit);

        if (! array_key_exists($cacheKey, $this->permissionCache)) {
            $this->permissionCache[$cacheKey] = $this->resolve($user, $organization, $academicUnit);
        }

        return collect($this->permissionCache[$cacheKey]);
    }

    /**
     * Invalidate one cached user/organization/scope result.
     */
    public function invalidate(User $user, Organization $organization, ?AcademicUnit $academicUnit = null): void
    {
        unset($this->permissionCache[$this->cacheKey($user, $organization, $academicUnit)]);
    }

    /**
     * Invalidate every cached permission result for an organization.
     */
    public function invalidateOrganization(Organization $organization): void
    {
        $prefix = $organization->getKey().':';

        foreach (array_keys($this->permissionCache) as $cacheKey) {
            if (str_starts_with($cacheKey, $prefix)) {
                unset($this->permissionCache[$cacheKey]);
            }
        }
    }

    /**
     * Invalidate all results held by this lifecycle instance.
     */
    public function flush(): void
    {
        $this->permissionCache = [];
    }

    /**
     * Resolve permissions without consulting the lifecycle cache.
     *
     * @return array<int, string>
     */
    private function resolve(User $user, Organization $organization, ?AcademicUnit $academicUnit): array
    {
        $membership = $user->organizationMemberships()
            ->where('organization_id', $organization->id)
            ->first();

        if (! $membership) {
            return [];
        }

        return $this->runWithOrganizationContext($organization, function () use ($organization, $membership, $academicUnit): array {
            if ($academicUnit && $academicUnit->organization_id !== $organization->id) {
                return [];
            }

            $assignments = MembershipRoleAssignment::query()
                ->where('organization_id', $organization->id)
                ->where('membership_id', $membership->getKey())
                ->when($academicUnit === null, fn ($query) => $query->whereNull('academic_unit_id'))
                ->with('role.permissions')
                ->get();

            if ($academicUnit) {
                $scopedUnitIds = $assignments->pluck('academic_unit_id')->filter()->unique()->values();
                $allowedScopeIds = AcademicUnitClosure::query()
                    ->where('organization_id', $organization->id)
                    ->where('descendant_id', $academicUnit->id)
                    ->whereIn('ancestor_id', $scopedUnitIds)
                    ->pluck('ancestor_id')
                    ->push($academicUnit->id);

                $assignments = $assignments->filter(fn (MembershipRoleAssignment $assignment): bool => $assignment->academic_unit_id === null || $allowedScopeIds->contains($assignment->academic_unit_id));
            }

            return $assignments
                ->filter(fn (MembershipRoleAssignment $assignment): bool => $assignment->role->organization_id === $organization->id)
                ->flatMap(fn (MembershipRoleAssignment $assignment): Collection => $assignment->role->permissions->pluck('code'))
                ->unique()
                ->values()
                ->all();
        });
    }

    /**
     * Build a lifecycle-local key for one authorization scope.
     */
    private function cacheKey(User $user, Organization $organization, ?AcademicUnit $academicUnit): string
    {
        return implode(':', [
            $organization->getKey(),
            $user->getKey(),
            $academicUnit?->getKey() ?? 'organization',
        ]);
    }

    /**
     * Execute a normalized authorization read under the target tenant.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    private function runWithOrganizationContext(Organization $organization, Closure $callback): mixed
    {
        if ($this->tenantContext->organization()?->is($organization)) {
            return $callback();
        }

        return $this->tenantContext->run($organization, fn (): mixed => $callback());
    }
}
