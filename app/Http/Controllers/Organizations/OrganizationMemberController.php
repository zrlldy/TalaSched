<?php

namespace App\Http\Controllers\Organizations;

use App\Enums\OrganizationRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organizations\UpdateOrganizationMemberRequest;
use App\Models\Membership;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class OrganizationMemberController extends Controller
{
    /**
     * Update the specified organization member's role.
     */
    public function update(UpdateOrganizationMemberRequest $request, Organization $organization, Membership $membership): RedirectResponse
    {
        $this->ensureMembershipBelongsToOrganization($membership, $organization);

        Gate::authorize('updateMember', $organization);

        $newRole = OrganizationRole::from($request->validated('role'));

        $membership->update(['role' => $newRole]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member role updated.')]);

        return to_route('organizations.edit', ['organization' => $organization->slug]);
    }

    /**
     * Remove the specified organization member.
     */
    public function destroy(Organization $organization, Membership $membership): RedirectResponse
    {
        $this->ensureMembershipBelongsToOrganization($membership, $organization);

        Gate::authorize('removeMember', $organization);

        $user = $membership->user;

        abort_if($organization->owner()?->is($user), 403, __('The organization owner cannot be removed.'));

        DB::transaction(function () use ($membership, $organization, $user): void {
            if ($user->isCurrentOrganization($organization)) {
                $user->switchToFallbackOrganization($organization);
            }

            $membership->delete();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member removed.')]);

        return to_route('organizations.edit', ['organization' => $organization->slug]);
    }

    private function ensureMembershipBelongsToOrganization(Membership $membership, Organization $organization): void
    {
        abort_unless($membership->organization_id === $organization->id, 404);
    }
}
