<?php

namespace App\Actions\Organizations;

use App\Authorization\OrganizationPermissionResolver;
use App\Enums\OrganizationPermission;
use App\Enums\OrganizationRole;
use App\Models\Membership;
use App\Models\MembershipRoleAssignment;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

class ProvisionOrganizationAuthorization
{
    public function __construct(
        private OrganizationPermissionResolver $permissionResolver,
        private TenantContext $tenantContext,
    ) {}

    /**
     * Ensure that every canonical permission exists.
     *
     * @return Collection<int|string, Permission>
     */
    public function ensureCanonicalPermissions(): Collection
    {
        $timestamp = now();

        Permission::query()->upsert(
            collect(OrganizationPermission::cases())
                ->map(fn (OrganizationPermission $permission): array => [
                    'code' => $permission->value,
                    'name' => $permission->label(),
                    'module' => $permission->module(),
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ])
                ->all(),
            ['code'],
            ['name', 'module', 'updated_at'],
        );

        return Permission::query()
            ->whereIn('code', array_map(fn (OrganizationPermission $permission): string => $permission->value, OrganizationPermission::cases()))
            ->get()
            ->keyBy('code');
    }

    /**
     * Provision the canonical roles and synchronize legacy membership roles.
     *
     * @param  Collection<int|string, Permission>|null  $permissions
     */
    public function handle(Organization $organization, ?Collection $permissions = null): void
    {
        $permissions ??= $this->ensureCanonicalPermissions();

        $this->tenantContext->run($organization, function () use ($organization, $permissions): void {
            DB::transaction(function () use ($organization, $permissions): void {
                $roles = collect();

                foreach (OrganizationRole::cases() as $legacyRole) {
                    $role = Role::query()->updateOrCreate(
                        [
                            'organization_id' => $organization->id,
                            'code' => $legacyRole->value,
                        ],
                        [
                            'name' => $this->roleName($legacyRole),
                            'is_system' => true,
                        ],
                    );

                    $role->permissions()->syncWithPivotValues(
                        $this->permissionIds($legacyRole, $permissions),
                        ['organization_id' => $organization->id],
                    );
                    $roles->put($legacyRole->value, $role);
                }

                $systemRoleIds = $roles
                    ->map(fn (Role $role): int => $role->getKey())
                    ->values()
                    ->all();

                $organization->memberships()->get()->each(function (Membership $membership) use ($organization, $roles, $systemRoleIds): void {
                    $legacyRole = $membership->role;
                    $role = $roles->get($legacyRole->value);

                    if (! $role instanceof Role) {
                        throw new LogicException("No normalized role exists for [{$legacyRole->value}].");
                    }

                    MembershipRoleAssignment::query()
                        ->where('membership_id', $membership->getKey())
                        ->whereIn('role_id', $systemRoleIds)
                        ->whereNull('academic_unit_id')
                        ->delete();

                    MembershipRoleAssignment::query()->create([
                        'organization_id' => $organization->id,
                        'membership_id' => $membership->getKey(),
                        'role_id' => $role->getKey(),
                        'academic_unit_id' => null,
                    ]);
                });
            });
        });

        $this->permissionResolver->invalidateOrganization($organization);
    }

    /**
     * Get the canonical name stored for a legacy role code.
     */
    private function roleName(OrganizationRole $role): string
    {
        return match ($role) {
            OrganizationRole::Owner => 'Owner',
            OrganizationRole::Admin => 'Administrator',
            OrganizationRole::Member => 'Member',
        };
    }

    /**
     * Resolve the permission IDs granted by a role.
     *
     * @param  Collection<int|string, Permission>  $permissions
     * @return array<int, int>
     */
    private function permissionIds(OrganizationRole $role, Collection $permissions): array
    {
        return collect($role->permissions())
            ->map(function (OrganizationPermission $permission) use ($permissions): int {
                $model = $permissions->get($permission->value);

                if (! $model instanceof Permission) {
                    throw new LogicException("Canonical permission [{$permission->value}] was not seeded.");
                }

                return $model->getKey();
            })
            ->values()
            ->all();
    }
}
