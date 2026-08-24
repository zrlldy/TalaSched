<?php

namespace App\Http\Controllers\Organizations;

use App\Actions\Organizations\CreateOrganization;
use App\Actions\Organizations\GetPendingOrganizationInvitations;
use App\Enums\CapabilityKey;
use App\Enums\OrganizationRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organizations\DeleteOrganizationRequest;
use App\Http\Requests\Organizations\SaveOrganizationRequest;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Subscriptions\UsageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationController extends Controller
{
    public function __construct(private UsageService $usage) {}

    /**
     * Display a listing of the user's organizations.
     */
    public function index(Request $request, GetPendingOrganizationInvitations $getPendingInvitations): Response
    {
        $user = $request->user();

        return Inertia::render('organizations/Index', [
            'organizations' => $user->toUserOrganizations(includeCurrent: true),
            'pendingInvitations' => $getPendingInvitations->handle($user),
        ]);
    }

    /**
     * Store a newly created organization.
     */
    public function store(SaveOrganizationRequest $request, CreateOrganization $createOrganization): RedirectResponse
    {
        $organization = $createOrganization->handle($request->user(), $request->validated('name'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Organization created.')]);

        return to_route('organizations.edit', ['organization' => $organization->slug]);
    }

    /**
     * Show the organization edit page.
     */
    public function edit(Request $request, Organization $organization): Response
    {
        $user = $request->user();

        return Inertia::render('organizations/Edit', [
            'organization' => [
                'id' => $organization->public_id,
                'name' => $organization->name,
                'slug' => $organization->slug,
            ],
            'members' => $organization->memberships()->with('user')->get()->map(function (Membership $membership) {
                $member = $membership->user;

                return [
                    'id' => $membership->public_id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'avatar' => $member->avatar ?? null,
                    'role' => $membership->role->value,
                    'role_label' => $membership->role->label(),
                ];
            }),
            'invitations' => $organization->invitations()
                ->whereNull('accepted_at')
                ->get()
                ->map(fn ($invitation) => [
                    'id' => $invitation->public_id,
                    'email' => $invitation->email,
                    'role' => $invitation->role->value,
                    'role_label' => $invitation->role->label(),
                    'created_at' => $invitation->created_at->toISOString(),
                ]),
            'permissions' => $user->toOrganizationPermissions($organization),
            'availableRoles' => OrganizationRole::assignable(),
            'roles' => $organization->roles()
                ->with('permissions')
                ->withCount('membershipAssignments')
                ->orderByDesc('is_system')
                ->orderBy('name')
                ->get()
                ->map(fn (Role $role): array => [
                    'code' => $role->code,
                    'name' => $role->name,
                    'is_system' => $role->is_system,
                    'members_count' => $role->membership_assignments_count,
                    'permissions' => $role->permissions
                        ->pluck('code')
                        ->values()
                        ->all(),
                ]),
            'availablePermissions' => Permission::query()
                ->orderBy('module')
                ->orderBy('name')
                ->get(['code', 'name', 'module'])
                ->map(fn (Permission $permission): array => [
                    'code' => $permission->code,
                    'name' => $permission->name,
                    'module' => $permission->module,
                ]),
        ]);
    }

    /**
     * Update the specified organization.
     */
    public function update(SaveOrganizationRequest $request, Organization $organization): RedirectResponse
    {
        Gate::authorize('update', $organization);

        $organization = DB::transaction(function () use ($request, $organization) {
            $organization = Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();

            $organization->update(['name' => $request->validated('name')]);

            return $organization;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Organization updated.')]);

        return to_route('organizations.edit', ['organization' => $organization->slug]);
    }

    /**
     * Switch the user's current organization.
     */
    public function switch(Request $request, Organization $organization): RedirectResponse
    {
        abort_unless($request->user()->belongsToOrganization($organization), 403);

        $request->user()->switchOrganization($organization);

        return back();
    }

    /**
     * Leave the specified organization.
     */
    public function leave(Request $request, Organization $organization): RedirectResponse
    {
        Gate::authorize('leave', $organization);

        $user = $request->user();

        DB::transaction(function () use ($user, $organization): void {
            $lockedOrganization = Organization::query()
                ->whereKey($organization->id)
                ->lockForUpdate()
                ->firstOrFail();
            $membership = Membership::query()
                ->where('organization_id', $lockedOrganization->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($user->isCurrentOrganization($organization)) {
                $user->switchToFallbackOrganization($organization);
            }

            $this->usage->releaseIfReserved($lockedOrganization, CapabilityKey::MaxMembers);
            $membership->delete();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('You left the organization ":name"', ['name' => $organization->name])]);

        return to_route('organizations.index');
    }

    /**
     * Delete the specified organization.
     */
    public function destroy(DeleteOrganizationRequest $request, Organization $organization): RedirectResponse
    {
        DB::transaction(function () use ($organization) {
            User::where('current_organization_id', $organization->id)
                ->each(fn (User $affectedUser) => $affectedUser->switchToFallbackOrganization($organization));

            $organization->invitations()->delete();
            $organization->delete();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Organization deleted.')]);

        return to_route('organizations.index');
    }
}
