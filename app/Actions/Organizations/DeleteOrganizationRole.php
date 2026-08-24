<?php

namespace App\Actions\Organizations;

use App\Models\Organization;
use App\Models\Role;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteOrganizationRole
{
    public function __construct(private TenantContext $tenantContext) {}

    /**
     * Delete an unassigned custom role.
     */
    public function handle(Organization $organization, Role $role): void
    {
        $this->tenantContext->run($organization, function () use ($role): void {
            DB::transaction(function () use ($role): void {
                $lockedRole = Role::query()->whereKey($role->getKey())->lockForUpdate()->firstOrFail();

                if ($lockedRole->is_system) {
                    throw ValidationException::withMessages([
                        'role' => __('System roles cannot be deleted.'),
                    ]);
                }

                if ($lockedRole->membershipAssignments()->exists()) {
                    throw ValidationException::withMessages([
                        'role' => __('Remove this role from all members before deleting it.'),
                    ]);
                }

                $lockedRole->delete();
            });
        });
    }
}
