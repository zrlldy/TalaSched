<?php

namespace App\Actions\Organizations;

use App\Audit\AuditLogger;
use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use App\Subscriptions\ProvisionOrganizationSubscription;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class CreateOrganization
{
    public function __construct(
        private AuditLogger $auditLogger,
        private ProvisionOrganizationAuthorization $provisionAuthorization,
        private ProvisionOrganizationSubscription $provisionSubscription,
        private TenantContext $tenantContext,
    ) {}

    /**
     * Create a new organization and add the user as owner.
     */
    public function handle(User $user, string $name): Organization
    {
        return DB::transaction(function () use ($user, $name) {
            $organization = Organization::create([
                'name' => $name,
                'owner_user_id' => $user->id,
            ]);

            $organization->memberships()->create([
                'user_id' => $user->id,
                'role' => OrganizationRole::Owner,
            ]);

            $this->tenantContext->run($organization, function (Organization $organization) use ($user): void {
                $this->provisionSubscription->handle($organization, $user);
                $this->provisionAuthorization->handle($organization);

                $this->auditLogger->record(
                    action: 'organization.created',
                    organization: $organization,
                    actor: $user,
                    subject: $organization,
                    after: [
                        'name' => $organization->name,
                        'slug' => $organization->slug,
                    ],
                );

                $user->switchOrganization($organization);
            });

            return $organization;
        });
    }
}
