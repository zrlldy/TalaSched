<?php

namespace App\Http\Controllers\Organizations;

use App\Actions\Organizations\ProvisionOrganizationAuthorization;
use App\Audit\AuditLogger;
use App\Enums\CapabilityKey;
use App\Enums\OrganizationRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organizations\UpdateOrganizationMemberRequest;
use App\Models\Membership;
use App\Models\Organization;
use App\Subscriptions\UsageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class OrganizationMemberController extends Controller
{
    public function __construct(
        private AuditLogger $auditLogger,
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

        DB::transaction(function () use ($membership, $newRole, $organization, $request): void {
            $lockedMembership = Membership::query()
                ->whereKey($membership->id)
                ->where('organization_id', $organization->id)
                ->lockForUpdate()
                ->firstOrFail();
            $before = ['role' => $lockedMembership->role->value];

            $lockedMembership->update(['role' => $newRole]);
            $this->provisionAuthorization->handle($organization);

            $this->auditLogger->record(
                action: 'organization.member_role_updated',
                organization: $organization,
                actor: $request->user(),
                subject: $lockedMembership,
                before: $before,
                after: ['role' => $lockedMembership->role->value],
            );
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member role updated.')]);

        return to_route('organizations.edit', ['organization' => $organization->slug]);
    }

    /**
     * Remove the specified organization member.
     */
    public function destroy(Request $request, Organization $organization, Membership $membership): RedirectResponse
    {
        $this->ensureMembershipBelongsToOrganization($membership, $organization);

        Gate::authorize('delete', $membership);

        DB::transaction(function () use ($membership, $organization, $request): void {
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

            $this->auditLogger->record(
                action: 'organization.member_removed',
                organization: $lockedOrganization,
                actor: $request->user(),
                subject: $lockedMembership,
                before: [
                    'role' => $lockedMembership->role->value,
                ],
                after: ['membership' => 'removed'],
            );
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member removed.')]);

        return to_route('organizations.edit', ['organization' => $organization->slug]);
    }

    private function ensureMembershipBelongsToOrganization(Membership $membership, Organization $organization): void
    {
        abort_unless($membership->organization_id === $organization->id, 404);
    }
}
