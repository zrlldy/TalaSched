<?php

namespace App\Actions\Organizations;

use App\Audit\AuditLogger;
use App\Enums\OrganizationRole;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class TransferOrganizationOwnership
{
    /**
     * Create a new ownership transfer action.
     */
    public function __construct(
        private AuditLogger $auditLogger,
        private ProvisionOrganizationAuthorization $provisionAuthorization,
        private TenantContext $tenantContext,
    ) {}

    /**
     * Transfer ownership to an existing organization member.
     */
    public function handle(Organization $organization, User $currentOwner, User $newOwner): Organization
    {
        Gate::forUser($currentOwner)->authorize('transferOwnership', $organization);

        if ($currentOwner->is($newOwner)) {
            throw new DomainException('The new owner must be different from the current owner.');
        }

        return $this->tenantContext->run($organization, function (Organization $organization) use ($currentOwner, $newOwner): Organization {
            return DB::transaction(function () use ($organization, $currentOwner, $newOwner): Organization {
                $lockedOrganization = Organization::query()
                    ->whereKey($organization->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                Gate::forUser($currentOwner)->authorize('transferOwnership', $lockedOrganization);

                $memberships = Membership::query()
                    ->where('organization_id', $lockedOrganization->getKey())
                    ->orderBy('user_id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('user_id');
                $currentMembership = $memberships->get($currentOwner->getKey());
                $newMembership = $memberships->get($newOwner->getKey());

                if (! $currentMembership instanceof Membership || $currentMembership->role !== OrganizationRole::Owner) {
                    throw new DomainException('The current user must be the explicit organization owner.');
                }

                if (! $newMembership instanceof Membership) {
                    throw new DomainException('The new owner must already be a member of the organization.');
                }

                $before = [
                    'owner_user_id' => $lockedOrganization->owner_user_id,
                    'owner_membership_user_ids' => $memberships
                        ->filter(fn (Membership $membership): bool => $membership->role === OrganizationRole::Owner)
                        ->pluck('user_id')
                        ->values()
                        ->all(),
                ];

                $memberships->each(function (Membership $membership) use ($newOwner): void {
                    if ($membership->user_id !== $newOwner->getKey() && $membership->role === OrganizationRole::Owner) {
                        $membership->updateQuietly(['role' => OrganizationRole::Admin]);
                    }
                });

                $newMembership->updateQuietly(['role' => OrganizationRole::Owner]);
                $lockedOrganization->update(['owner_user_id' => $newOwner->getKey()]);

                $this->provisionAuthorization->handle($lockedOrganization);

                $after = [
                    'owner_user_id' => $lockedOrganization->owner_user_id,
                    'owner_membership_user_ids' => [$newOwner->getKey()],
                ];

                $this->auditLogger->record(
                    action: 'organization.ownership_transferred',
                    organization: $lockedOrganization,
                    actor: $currentOwner,
                    subject: $lockedOrganization,
                    before: $before,
                    after: $after,
                );

                return $lockedOrganization->fresh();
            }, attempts: 3);
        });
    }
}
