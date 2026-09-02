<?php

namespace App\Actions\Organizations;

use App\Audit\AuditLogger;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateOrganizationRole
{
    public function __construct(
        private AuditLogger $auditLogger,
        private TenantContext $tenantContext,
        private SyncOrganizationRolePermissions $syncPermissions,
    ) {}

    /**
     * Create a custom role and grant its selected permissions.
     *
     * @param  array<int, string>  $permissionCodes
     */
    public function handle(Organization $organization, string $name, array $permissionCodes, ?User $actor = null): Role
    {
        return $this->tenantContext->run($organization, function () use ($actor, $organization, $name, $permissionCodes): Role {
            return DB::transaction(function () use ($actor, $organization, $name, $permissionCodes): Role {
                $role = $organization->roles()->create([
                    'code' => $this->uniqueCode($organization, $name),
                    'name' => $name,
                    'is_system' => false,
                ]);

                $role = $this->syncPermissions->handle($role, $permissionCodes);

                $this->auditLogger->record(
                    action: 'organization.role_created',
                    organization: $organization,
                    actor: $actor,
                    subject: $role,
                    after: $this->snapshot($role),
                );

                return $role;
            });
        });
    }

    /**
     * @return array{name: string, code: string, permission_codes: array<int, string>}
     */
    private function snapshot(Role $role): array
    {
        return [
            'name' => $role->name,
            'code' => $role->code,
            'permission_codes' => $role->permissions
                ->pluck('code')
                ->sort()
                ->values()
                ->all(),
        ];
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
