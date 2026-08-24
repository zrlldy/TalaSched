<?php

namespace App\Http\Controllers\Organizations;

use App\Actions\Organizations\CreateOrganizationRole;
use App\Actions\Organizations\DeleteOrganizationRole;
use App\Actions\Organizations\UpdateOrganizationRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organizations\CreateOrganizationRoleRequest;
use App\Http\Requests\Organizations\UpdateOrganizationRoleRequest;
use App\Models\Organization;
use App\Models\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class OrganizationRoleController extends Controller
{
    public function store(CreateOrganizationRoleRequest $request, Organization $organization, CreateOrganizationRole $createRole): RedirectResponse
    {
        Gate::authorize('create', [Role::class, $organization]);

        $createRole->handle(
            $organization,
            $request->validated('name'),
            $request->validated('permissions', []),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Custom role created.')]);

        return to_route('organizations.edit', ['organization' => $organization->slug]);
    }

    public function update(
        UpdateOrganizationRoleRequest $request,
        Organization $organization,
        Role $role,
        UpdateOrganizationRole $updateRole,
    ): RedirectResponse {
        $this->ensureRoleBelongsToOrganization($role, $organization);
        Gate::authorize('update', $role);

        $updateRole->handle(
            $organization,
            $role,
            $request->validated('name'),
            $request->validated('permissions', []),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Custom role updated.')]);

        return to_route('organizations.edit', ['organization' => $organization->slug]);
    }

    public function destroy(
        Organization $organization,
        Role $role,
        DeleteOrganizationRole $deleteRole,
    ): RedirectResponse {
        $this->ensureRoleBelongsToOrganization($role, $organization);
        Gate::authorize('delete', $role);

        $deleteRole->handle($organization, $role);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Custom role deleted.')]);

        return to_route('organizations.edit', ['organization' => $organization->slug]);
    }

    private function ensureRoleBelongsToOrganization(Role $role, Organization $organization): void
    {
        abort_unless($role->organization_id === $organization->id, 404);
    }
}
