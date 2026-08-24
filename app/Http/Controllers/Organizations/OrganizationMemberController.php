<?php

namespace App\Http\Controllers\Organizations;

use App\Actions\Organizations\ProvisionOrganizationAuthorization;
use App\Enums\CapabilityKey;
use App\Enums\OrganizationRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organizations\UpdateOrganizationMemberRequest;
use App\Models\Membership;
use App\Models\Organization;
use App\Subscriptions\UsageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class OrganizationMemberController extends Controller
{
    public function __construct(
        private ProvisionOrganizationAuthorization $provisionAuthorization,
        private UsageService $usage,
    ) {}

    /**
     * Update the specified organization member's role.
     */
    public function update(UpdateOrganizationMemberRequest $request, Organization $organization, Membership $membership): RedirectResponse
    {
        $this->ensureMembershipBelongsToOrganization($membership, $organization);

        $newRole = OrganizationRole::from($request->validated('role'));

        Gate::authorize('update', [$membership, $newRole]);

        DB::transaction(function () use ($membership, $organization, $newRole): void {
            $membership->update(['role' => $newRole]);
            $this->provisionAuthorization->handle($organization);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member role updated.')]);

        return to_route('organizations.edit', ['organization' => $organization->slug]);
    }

    /**
     * Remove the specified organization member.
     */
    public function destroy(Organization $organization, Membership $membership): RedirectResponse
    {
        $this->ensureMembershipBelongsToOrganization($membership, $organization);

        Gate::authorize('delete', $membership);

        DB::transaction(function () use ($membership, $organization): void {
            $lockedOrganization = Organization::query()
                ->whereKey($organization->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedMembership = Membership::query()
                ->whereKey($membership->id)
                ->where('organization_id', $lockedOrganization->id)
                ->lockForUpdate()
                ->firstOrFail();
            $user = $lockedMembership->user;

            if ($user->isCurrentOrganization($organization)) {
                $user->switchToFallbackOrganization($organization);
            }

            $this->usage->releaseIfReserved($lockedOrganization, CapabilityKey::MaxMembers);
            $lockedMembership->delete();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member removed.')]);

        return to_route('organizations.edit', ['organization' => $organization->slug]);
    }

    private function ensureMembershipBelongsToOrganization(Membership $membership, Organization $organization): void
    {
        abort_unless($membership->organization_id === $organization->id, 404);
    }
}
