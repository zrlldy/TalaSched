<?php

namespace App\Actions\Organizations;

use App\Models\Organization;
use App\Models\Role;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateOrganizationRole
{
    public function __construct(
        private TenantContext $tenantContext,
        private SyncOrganizationRolePermissions $syncPermissions,
    ) {}

    /**
     * Create a custom role and grant its selected permissions.
     *
     * @param  array<int, string>  $permissionCodes
     */
    public function handle(Organization $organization, string $name, array $permissionCodes): Role
    {
        return $this->tenantContext->run($organization, function () use ($organization, $name, $permissionCodes): Role {
            return DB::transaction(function () use ($organization, $name, $permissionCodes): Role {
                $role = $organization->roles()->create([
                    'code' => $this->uniqueCode($organization, $name),
                    'name' => $name,
                    'is_system' => false,
                ]);

                return $this->syncPermissions->handle($role, $permissionCodes);
            });
        });
    }

    private function uniqueCode(Organization $organization, string $name): string
    {
        $base = substr(Str::slug($name) ?: 'custom-role', 0, 56);
        $candidate = $base;
        $suffix = 2;

        while ($organization->roles()->where('code', $candidate)->exists()) {
            $candidate = substr($base, 0, 56 - strlen((string) $suffix) - 1).'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }
}
